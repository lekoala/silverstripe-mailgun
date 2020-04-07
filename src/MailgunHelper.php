<?php

namespace LeKoala\Mailgun;

use \Exception;
use Mailgun\Mailgun;
use SilverStripe\Control\Director;
use SilverStripe\Core\Environment;
use SilverStripe\Control\Email\Email;
use SilverStripe\Control\Email\Mailer;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\Core\Injector\Injector;
use LeKoala\Mailgun\MailgunSwiftTransport;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Control\Email\SwiftMailer;
use Mailgun\HttpClient\HttpClientConfigurator;

/**
 * This configurable class helps decoupling the api client from SilverStripe
 */
class MailgunHelper
{
    use Configurable;

    const DEFAULT_ENDPOINT = 'https://api.mailgun.net/v3';
    const EU_ENDPOINT = 'https://api.eu.mailgun.net/v3';

    /**
     * Client instance
     *
     * @var Mailgun
     */
    protected static $client;

    /**
     * Get the mailer instance
     *
     * @return SilverStripe\Control\Email\SwiftMailer
     */
    public static function getMailer()
    {
        return Injector::inst()->get(Mailer::class);
    }

    /**
     * @return boolean
     */
    public static function isMailgunMailer()
    {
        return self::getMailer()->getSwiftMailer()->getTransport() instanceof MailgunSwiftTransport;
    }

    /**
     * Get the api client instance
     * @return Mailgun
     * @throws Exception
     */
    public static function getClient()
    {
        if (!self::$client) {
            $key = self::config()->api_key;
            if (empty($key)) {
                throw new \Exception("api_key is not configured for " . __CLASS__);
            }
            $endpoint = self::DEFAULT_ENDPOINT;
            if (self::config()->endpoint) {
                $endpoint = self::config()->endpoint;
            }
            $configurator = new HttpClientConfigurator();
            $configurator->setApiKey($key);
            $configurator->setEndpoint($endpoint);
            if (self::config()->debug) {
                $configurator->setDebug(true);
            }
            self::$client = new Mailgun($configurator);
        }
        return self::$client;
    }

    /**
     * Get the log folder and create it if necessary
     *
     * @return string
     */
    public static function getLogFolder()
    {
        $logFolder = BASE_PATH . '/' . self::config()->log_folder;
        if (!is_dir($logFolder)) {
            mkdir($logFolder, 0755, true);
        }
        return $logFolder;
    }

    /**
     * @return string
     */
    public static function getDomain()
    {
        if ($domain = self::config()->domain) {
            return $domain;
        }
        if ($domain = Environment::getEnv('MAILGUN_DOMAIN')) {
            return $domain;
        }
        throw new Exception("MAILGUN_DOMAIN not set");
    }

    /**
     * Process environment variable to configure this module
     *
     * @return void
     */
    public static function init()
    {
        // Regular api key used for sending emails
        $api_key = Environment::getEnv('MAILGUN_API_KEY');
        if ($api_key) {
            self::config()->api_key = $api_key;
        }

        $domain = Environment::getEnv('MAILGUN_DOMAIN');
        if ($domain) {
            self::config()->domain = $domain;
        }

        // Set a custom endpoint
        $endpoint = Environment::getEnv('MAILGUN_ENDPOINT');
        if ($endpoint) {
            self::config()->endpoint = $endpoint;
        }

        // Debug
        $debug = Environment::getEnv('MAILGUN_DEBUG');
        if ($debug) {
            self::config()->debug = $debug;
        }

        // Disable sending
        $sending_disabled = Environment::getEnv('MAILGUN_SENDING_DISABLED');
        if ($sending_disabled) {
            self::config()->disable_sending = $sending_disabled;
        }

        // Log all outgoing emails (useful for testing)
        $enable_logging = Environment::getEnv('MAILGUN_ENABLE_LOGGING');
        if ($enable_logging) {
            self::config()->enable_logging = $enable_logging;
        }

        // We have a key, we can register the transport
        if (self::config()->api_key) {
            self::registerTransport();
        }
    }

    /**
     * Register the transport with the client
     *
     * @return SilverStripe\Control\Email\SwiftMailer The updated swift mailer
     * @throws Exception
     */
    public static function registerTransport()
    {
        $client = self::getClient();
        $mailer = self::getMailer();
        if (!$mailer instanceof SwiftMailer) {
            throw new Exception("Mailer must be an instance of " . SwiftMailer::class . " instead of " . get_class($mailer));
        }
        $transport = new MailgunSwiftTransport($client);
        $newSwiftMailer = $mailer->getSwiftMailer()->newInstance($transport);
        $mailer->setSwiftMailer($newSwiftMailer);
        return $mailer;
    }


    /**
     * Resolve default send from address
     *
     * Keep in mind that an email using send() without a from
     * will inject the admin_email. Therefore, SiteConfig
     * will not be used
     *
     * @param string $from
     * @param bool $createDefault
     * @return string
     */
    public static function resolveDefaultFromEmail($from = null, $createDefault = true)
    {
        $original_from = $from;
        if (!empty($from)) {
            // If we have a sender, validate its email
            $from = EmailUtils::get_email_from_rfc_email($from);
            if (filter_var($from, FILTER_VALIDATE_EMAIL)) {
                return $original_from;
            }
        }
        // Look in siteconfig for default sender
        $config = SiteConfig::current_site_config();
        $config_field = self::config()->siteconfig_from;
        if ($config_field && !empty($config->$config_field)) {
            return $config->$config_field;
        }
        // Use admin email
        if ($admin = Email::config()->admin_email) {
            return $admin;
        }
        // If we still don't have anything, create something based on the domain
        if ($createDefault) {
            return self::createDefaultEmail();
        }
        return false;
    }

    /**
     * Resolve default send to address
     *
     * @param string $to
     * @return string
     */
    public static function resolveDefaultToEmail($to = null)
    {
        // In case of multiple recipients, do not validate anything
        if (is_array($to) || strpos($to, ',') !== false) {
            return $to;
        }
        $original_to = $to;
        if (!empty($to)) {
            $to = EmailUtils::get_email_from_rfc_email($to);
            if (filter_var($to, FILTER_VALIDATE_EMAIL)) {
                return $original_to;
            }
        }
        $config = SiteConfig::current_site_config();
        $config_field = self::config()->siteconfig_to;
        if ($config_field && !empty($config->$config_field)) {
            return $config->$config_field;
        }
        if ($admin = Email::config()->admin_email) {
            return $admin;
        }
        return false;
    }

    /**
     * Create a sensible default address based on domain name
     *
     * @return string
     */
    public static function createDefaultEmail()
    {
        $fulldom = Director::absoluteBaseURL();
        $host = parse_url($fulldom, PHP_URL_HOST);
        if (!$host) {
            $host = 'localhost';
        }
        $dom = str_replace('www.', '', $host);

        return 'postmaster@' . $dom;
    }
}
