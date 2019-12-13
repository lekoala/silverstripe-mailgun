<?php

namespace LeKoala\Mailgun;

use Exception;
use Psr\Log\LoggerInterface;
use SilverStripe\Control\Director;
use SilverStripe\Core\Environment;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Security\Permission;
use SilverStripe\Core\Injector\Injector;

/**
 * Provide extensions points for handling the webhook
 *
 * @link https://www.mailgun.com/guides/your-guide-to-webhooks/
 * @author LeKoala <thomas@lekoala.be>
 */
class MailgunController extends Controller
{
    const TYPE_CLICKED = 'clicked';
    const TYPE_COMPLAINED = 'complained';
    const TYPE_DELIVERED = 'delivered';
    const TYPE_OPENED = 'opened';
    const TYPE_PERMANENT_FAIL = 'permanent_fail';
    const TYPE_TEMPORARY_FAIL = 'temporary_fail';
    const TYPE_UNSUBSCRIBED = 'unsubscribed';

    protected $eventsCount = 0;
    protected $skipCount = 0;
    private static $allowed_actions = [
        'incoming',
        'test',
        'configure_inbound_emails'
    ];

    /**
     * Inject public dependencies into the controller
     *
     * @var array
     */
    private static $dependencies = [
        'logger' => '%$Psr\Log\LoggerInterface',
    ];

    /**
     * @var Psr\Log\LoggerInterface
     */
    public $logger;

    public function index(HTTPRequest $req)
    {
        return $this->render([
            'Title' => 'Mailgun',
            'Content' => 'Please use a dedicated action'
        ]);
    }

    /**
     * You can also see /resources/webhook.txt
     *
     * @param HTTPRequest $req
     */
    public function test(HTTPRequest $req)
    {
        if (!Director::isDev()) {
            return 'You can only test in dev mode';
        }

        $file = $this->getRequest()->getVar('file');
        if ($file) {
            $data = file_get_contents(Director::baseFolder() . '/' . rtrim($file, '/'));
        } else {
            $data = file_get_contents(dirname(__DIR__) . '/resources/webhook.txt');
        }

        $this->processPayload($data, 'TEST');

        return 'TEST OK - ' . $this->eventsCount . ' events processed / ' . $this->skipCount . ' events skipped';
    }

    /**
     * @link https://support.Mailgun.com/customer/portal/articles/2039614-enabling-inbound-email-relaying-relay-webhooks
     * @param HTTPRequest $req
     * @return string
     */
    public function configure_inbound_emails(HTTPRequest $req)
    {
        if (!Director::isDev() && !Permission::check('ADMIN')) {
            return 'You must be in dev mode or be logged as an admin';
        }

        $clearExisting = $req->getVar('clear_existing');
        $clearWebhooks = $req->getVar('clear_webhooks');
        $clearInbound = $req->getVar('clear_inbound');
        if ($clearExisting) {
            echo '<strong>Existing inbounddomains and relay webhooks will be cleared</strong><br/>';
        } else {
            echo 'You can clear existing inbound domains and relay webhooks by passing ?clear_existing=1&clear_webhooks=1&clear_inbound=1<br/>';
        }

        $client = MailgunHelper::getClient();

        $inbound_domain = Environment::getEnv('MAILGUN_INBOUND_DOMAIN');
        if (!$inbound_domain) {
            die('You must define a key MAILGUN_INBOUND_DOMAIN');
        }

        //TODO : use routing to implement this

        throw new Exception("Not implemented yet");
    }

    /**
     * Handle incoming webhook
     *
     * @param HTTPRequest $req
     */
    public function incoming(HTTPRequest $req)
    {
        $batchId = uniqid();

        $json = file_get_contents('php://input');

        // By default, return a valid response
        $response = $this->getResponse();
        $response->setStatusCode(200);
        $response->setBody('NO DATA');

        if (!$json) {
            return $response;
        }

        $webhookLogDir = Environment::getEnv('MAILGUN_WEBHOOK_LOG_DIR');
        if ($webhookLogDir) {
            $dir = rtrim(Director::baseFolder(), '/') . '/' . rtrim($webhookLogDir, '/');

            if (!is_dir($dir) && Director::isDev()) {
                mkdir($dir, 0755, true);
            }

            if (is_dir($dir)) {
                $payload['@headers'] = $req->getHeaders();
                $prettyPayload = json_encode(json_decode($json), JSON_PRETTY_PRINT);
                $time = date('Ymd-His');
                file_put_contents($dir . '/' . $time . '_' . $batchId . '.json', $prettyPayload);
            } else {
                $this->getLogger()->debug("Directory $dir does not exist");
            }
        }

        $payload = json_decode($json, JSON_OBJECT_AS_ARRAY);

        try {
            $this->processPayload($payload, $batchId);
        } catch (Exception $ex) {
            // Maybe processing payload will create exceptions, but we
            // catch them to send a proper response to the API
            $logLevel = self::config()->log_level ? self::config()->log_level : 7;
            $this->getLogger()->log($ex->getMessage(), $logLevel);
        }

        $response->setBody('OK');

        return $response;
    }

    /**
     * A receiving URI must be public, so webhooks should be secured with a signature,
     * time stamp and token to create a hash map using an API key to verify that the data
     * is coming from the developer’s ESP. Users should program their application to check
     * that hash map and compare it to that of the ESP, and then allow the post to be made only if it matches.
     *
     * To verify the webhook is originating from their ESP, users should concatenate time stamp and token values,
     * encode the resulting string with the HMAC algorithm (using the ESP’s supplied API Key as a key and SHA256 digest mode),
     * and compare the resulting hexdigest to the signature.
     * Optionally, users can cache the token value locally and not honor any subsequent request with the same token. This will prevent replay attacks.
     *
     * @return bool
     */
    protected function verifyCall()
    {
        //TODO: implement this
        return true;
    }

    /**
     * Process data
     *
     * @param string $payload
     * @param string $batchId
     */
    protected function processPayload($payload, $batchId = null)
    {
        $this->extend('beforeProcessPayload', $payload, $batchId);

        // TODO: parse payload properly
        // foreach ($payload as $r) {
        //     $this->eventsCount++;
        //     $this->extend('onAnyEvent', $data, $type);

        //     switch ($type) {
        //             //Click, Open
        //         case self::TYPE_CLICKED:
        //         case self::TYPE_OPENED:
        //             $this->extend('onEngagementEvent', $data, $type);
        //             break;
        //             //Generation Failure, Generation Rejection
        //         case self::TYPE_DELIVERED:
        //             $this->extend('onGenerationEvent', $data, $type);
        //             break;
        //             //Bounce, Delivery, Injection, SMS Status, Spam Complaint, Out of Band, Policy Rejection, Delay
        //         case self::TYPE_COMPLAINED:
        //         case self::TYPE_PERMANENT_FAIL:
        //         case self::TYPE_TEMPORARY_FAIL:
        //             $this->extend('onMessageEvent', $data, $type);
        //             break;
        //             //List Unsubscribe, Link Unsubscribe
        //         case self::TYPE_UNSUBSCRIBED:
        //             $this->extend('onUnsubscribeEvent', $data, $type);
        //             break;
        //     }
        // }

        $this->extend('afterProcessPayload', $payload, $batchId);
    }


    /**
     * Get logger
     *
     * @return Psr\SimpleCache\CacheInterface
     */
    public function getLogger()
    {
        return $this->logger;
    }
}
