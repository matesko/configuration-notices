<?php

declare(strict_types=1);

namespace BobdenOtter\ConfigurationNotices;

use Bolt\Canonical;
use Bolt\Configuration\Config;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Tightenco\Collect\Support\Collection;

class Checks
{
    protected $defaultDomainPartials = ['.dev', 'dev.', 'devel.', 'development.', 'test.', '.test', 'new.', '.new', '.local', 'local.', '.wip'];

    /** @var Config */
    private $boltConfig;

    /** @var Request */
    private $request;

    /** @var Collection */
    private $extensionConfig;

    private $notices = [];
    private $severity = 0;

    /** @var Container */
    private $container;

    public function __construct(Config $boltConfig, Request $request, Collection $extensionConfig, Container $container)
    {
        $this->boltConfig = $boltConfig;
        $this->request = $request;
        $this->extensionConfig = $extensionConfig;
        $this->container = $container;
    }

    public function getResults(): array
    {
        if ($this->request->get('_route') !== 'bolt_dashboard') {
            return [
                'severity' => 0,
                'notices' => null,
            ];
        }

        $this->liveCheck();
        $this->newContentTypeCheck();
        $this->duplicateTaxonomyAndContentTypeCheck();
        $this->singleHostnameCheck();
        $this->ipAddressCheck();
        $this->topLevelCheck();
        $this->writableFolderCheck();
        $this->thumbsFolderCheck();
        $this->canonicalCheck();
        $this->imageFunctionsCheck();
        $this->maintenanceCheck();

        return [
            'severity' => $this->severity,
            'notices' => $this->notices,
        ];
    }

    /**
     * Check whether the site is live or not.
     */
    private function liveCheck(): void
    {
        if ($this->getParameter('kernel.environment') === 'prod' && $this->getParameter('kernel.debug') !== '1') {
            return;
        }

        $host = parse_url($this->request->getSchemeAndHttpHost());

        // If we have an IP-address, we assume it's "dev"
        if (filter_var($host['host'], FILTER_VALIDATE_IP) !== false) {
            return;
        }

        $domainPartials = array_unique(array_merge(
            $this->extensionConfig->get('local_domains'),
            $this->defaultDomainPartials
        ));

        foreach ($domainPartials as $partial) {
            if (mb_strpos($host['host'], $partial) !== false) {
                return;
            }
        }

        $this->setSeverity(2);
        $this->setNotice(
            "It seems like this website is running on a <strong>non-development environment</strong>, 
             while development mode is enabled (<code>APP_ENV=dev</code> and/or <code>APP_DEBUG=1</code>). 
             Ensure debug is disabled in production environments, otherwise it will 
             result in an extremely large <code>var/cache</code> folder and a measurable reduced 
             performance.",
            "If you wish to hide this message, add a key to your <abbr title='config/extensions/bobdenotter-configurationnotices.yaml'>
             config <code>yaml</code></abbr> file with a (partial) domain name in it, that should be 
             seen as a development environment: <code>local_domains: [ '.foo' ]</code>."
        );
    }

    /**
     * Check whether ContentTypes have been added without flushing the cache afterwards
     */
    private function newContentTypeCheck(): void
    {
        $fromParameters = explode('|', $this->getParameter('bolt.requirement.contenttypes'));

        foreach ($this->boltConfig->get('contenttypes') as $contentType) {
            if (! in_array($contentType->get('slug'), $fromParameters, true)) {
                $notice = sprintf("A <b>new ContentType</b> ('%s') was added. Make sure to <a href='./clearcache'>clear the cache</a>, so it shows up correctly.", $contentType->get('name'));
                $info = "By clearing the cache, you'll ensure the routing requirements are updated, allowing Bolt to generate the correct links to the new ContentType.";

                $this->setSeverity(3);
                $this->setNotice($notice, $info);

                return;
            }
        }
    }

    /**
     * Check whether there is a ContentType and Taxonomy with the same name, because that will confuse routing
     */
    private function duplicateTaxonomyAndContentTypeCheck(): void
    {
        $configContent = $this->boltConfig->get('contenttypes');
        $configTaxo = $this->boltConfig->get('taxonomies');

        $contenttypes = collect($configContent->pluck('slug'))->merge($configContent->pluck('singular_slug'))->unique();
        $taxonomies = collect($configTaxo->pluck('slug'))->merge($configTaxo->pluck('singular_slug'))->unique();

        $overlap = $contenttypes->intersect($taxonomies);

        if ($overlap->isNotEmpty()) {
            $notice = sprintf("The ContentTypes and Taxonomies contain <strong>overlapping identifiers</strong>: <code>%s</code>.", $overlap->implode('</code>, <code>'));
            $info = "Edit your <code>contenttypes.yaml</code> or your <code>taxonomies.yaml</code>, to ensure that all the used <code>slug</code>s and <code>singular_slug</code>s are unique.";

            $this->setSeverity(2);
            $this->setNotice($notice, $info);
        }

    }
    
    /**
     * Check whether or not we're running on a hostname without TLD, like 'http://localhost'.
     */
    private function singleHostnameCheck(): void
    {
        $hostname = $this->request->getHttpHost();

        if (mb_strpos($hostname, '.') === false) {
            $notice = "You are using <code>${hostname}</code> as host name. Some browsers have problems with sessions on hostnames that do not have a <code>.tld</code> in them.";
            $info = 'If you experience difficulties logging on, either configure your webserver to use a hostname with a dot in it, or use another browser.';

            $this->setSeverity(1);
            $this->setNotice($notice, $info);
        }
    }

    /**
     * Check whether or not we're running on a hostname without TLD, like 'http://localhost'.
     */
    private function ipAddressCheck(): void
    {
        $hostname = $this->request->getHttpHost();

        if (filter_var($hostname, FILTER_VALIDATE_IP)) {
            $notice = "You are using the <strong>IP address</strong> <code>${hostname}</code> as host name. This is known to cause problems with sessions on certain browsers.";
            $info = 'If you experience difficulties logging on, either configure your webserver to use a proper hostname, or use another browser.';

            $this->setSeverity(1);
            $this->setNotice($notice, $info);
        }
    }

    /**
     * Ensure we're running in the webroot, and not in a subfolder
     */
    private function topLevelCheck(): void
    {
        $base = $this->request->getBaseUrl();

        if (! empty($base)) {
            $notice = 'You are using Bolt in a subfolder, <strong>instead of the webroot</strong>.';
            $info = "It is recommended to use Bolt from the 'web root', so that it is in the top level. If you wish to 
                use Bolt for only part of a website, we recommend setting up a subdomain like <code>news.example.org</code>.";

            $this->setSeverity(1);
            $this->setNotice($notice, $info);
        }
    }

    /**
     * Check if some common file locations are writable.
     */
    private function writableFolderCheck(): void
    {
        $fileName = '/configtester_' . date('Y-m-d-h-i-s') . '.txt';
        $fileSystems = ['files', 'config', 'cache'];

        if ($this->getParameter('env(DATABASE_DRIVER)') === 'pdo_sqlite') {
            $fileSystems[] = 'database';
        }

        foreach ($fileSystems as $fileSystem) {
            if (! $this->isWritable($fileSystem, $fileName)) {
                $baseName = $this->boltConfig->getPath('root');
                $folderName = str_replace($baseName, '…', $this->boltConfig->getPath($fileSystem));
                $notice = 'Bolt needs to be able to <strong>write files to</strong> the "' . $fileSystem . '" folder, but it doesn\'t seem to be writable.';
                $info = 'Make sure the folder <code>' . $folderName . '</code> exists, and is writable to the webserver.';

                $this->setSeverity(2);
                $this->setNotice($notice, $info);
            }
        }
    }

    /**
     * Check if the thumbs/ folder is writable, if `save_files: true`
     */
    private function thumbsFolderCheck(): void
    {
        if (! $this->boltConfig->get('general/thumbnails/save_files')) {
            return;
        }

        $fileName = '/configtester_' . date('Y-m-d-h-i-s') . '.txt';

        if (! $this->isWritable('thumbs', $fileName)) {
            $notice = "Bolt is configured to save thumbnails to disk for performance, but the <code>thumbs/</code> folder doesn't seem to be writable.";
            $info = 'Make sure the folder exists, and is writable to the webserver.';

            $this->setSeverity(2);
            $this->setNotice($notice, $info);
        }
    }

    /**
     * Check if the current url matches the canonical.
     */
    private function canonicalCheck(): void
    {
        $hostname = parse_url(strtok($this->request->getUri(), '?'));

        if ($hostname['scheme'] !== $_SERVER['CANONICAL_SCHEME'] || $hostname['host'] !== $_SERVER['CANONICAL_HOST']) {
            $canonical = sprintf('%s://%s', $_SERVER['CANONICAL_SCHEME'], $_SERVER['CANONICAL_HOST']);
            $login = sprintf('%s%s', $canonical, $this->getParameter('bolt.backend_url'));
            $notice = "The <strong>canonical hostname</strong> is set to <code>${canonical}</code> in <code>config.yaml</code>,
                but you are currently logged in using another hostname. This might cause issues with uploaded files, or 
                links inserted in the content.";
            $info = sprintf(
                "Log in on Bolt using the proper URL: <code><a href='%s'>%s</a></code>.",
                $login,
                $login
            );

            $this->setSeverity(1);
            $this->setNotice($notice, $info);
        }
    }

    /**
     * Check if the exif, fileinfo and gd extensions are enabled / compiled into PHP.
     */
    private function imageFunctionsCheck(): void
    {
        if (! extension_loaded('exif') || ! function_exists('exif_read_data')) {
            $notice = 'The function <code>exif_read_data</code> does not exist, which means that Bolt can not create thumbnail images.';
            $info = "Make sure the <code>php-exif</code> extension is enabled <u>and</u> compiled into your PHP setup. See <a href='http://php.net/manual/en/exif.installation.php'>here</a>.";

            $this->setSeverity(1);
            $this->setNotice($notice, $info);
        }

        if (! extension_loaded('fileinfo') || ! class_exists('finfo')) {
            $notice = 'The class <code>finfo</code> does not exist, which means that Bolt can not create thumbnail images.';
            $info = "Make sure the <code>fileinfo</code> extension is enabled <u>and</u> compiled into your PHP setup. See <a href='http://php.net/manual/en/fileinfo.installation.php'>here</a>.";

            $this->setSeverity(1);
            $this->setNotice($notice, $info);
        }

        if (! extension_loaded('gd') || ! function_exists('gd_info')) {
            $notice = 'The function <code>gd_info</code> does not exist, which means that Bolt can not create thumbnail images.';
            $info = "Make sure the <code>gd</code> extension is enabled <u>and</u> compiled into your PHP setup. See <a href='http://php.net/manual/en/image.installation.php'>here</a>.";

            $this->setSeverity(1);
            $this->setNotice($notice, $info);
        }
    }

    /**
     * If the site is in maintenance mode, show this on the dashboard.
     */
    protected function maintenanceCheck(): void
    {
        if ($this->boltConfig->get('general/maintenance_mode', false)) {
            $notice = "Bolt's <strong>maintenance mode</strong> is enabled. This means that non-authenticated users will not be able to see the website.";
            $info = 'To make the site available to the general public again, set <code>maintenance_mode: false</code> in your <code>config.yaml</code> file.';

            $this->setSeverity(1);
            $this->setNotice($notice, $info);
        }
    }

    private function isWritable($fileSystem, $filename): bool
    {
        $filePath = $this->boltConfig->getPath($fileSystem) . $filename;
        $filesystem = new Filesystem();

        try {
            $filesystem->dumpFile($filePath, 'ok');
            $filesystem->remove($filePath);
        } catch (\Throwable $e) {
            return false;
        }

        return true;
    }

    private function setSeverity(int $severity): void
    {
        $this->severity = max($severity, $this->severity);
    }

    private function setNotice(string $notice, ?string $info = null): void
    {
        $this->notices[] = [
            'notice' => $notice,
            'info' => $info,
        ];
    }

    private function getParameter(string $parameter): ?string
    {
        return $this->container->getParameter($parameter);
    }
}
