<?php

namespace LeKoala\Mailgun;

use DateTime;
use \Exception;
use SilverStripe\Forms\Tab;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\TabSet;
use SilverStripe\ORM\ArrayLib;
use SilverStripe\ORM\ArrayList;
use SilverStripe\View\ArrayData;
use SilverStripe\Control\Session;
use SilverStripe\Forms\DateField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FormField;
use SilverStripe\Forms\TextField;
use LeKoala\Mailgun\MailgunHelper;
use SilverStripe\Control\Director;
use SilverStripe\Core\Environment;
use SilverStripe\Forms\FormAction;
use SilverStripe\Admin\LeftAndMain;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Security\Security;
use SilverStripe\View\ViewableData;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Control\Email\Email;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Security\Permission;
use Mailgun\Model\Event\EventResponse;
use SilverStripe\Forms\CompositeField;
use SilverStripe\SiteConfig\SiteConfig;
use Mailgun\Model\Webhook\IndexResponse as WebhookIndexResponse;
use SilverStripe\Core\Injector\Injector;
use LeKoala\Mailgun\MailgunSwiftTransport;
use SilverStripe\Control\Email\SwiftMailer;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Security\PermissionProvider;
use SilverStripe\Forms\GridField\GridFieldConfig;
use SilverStripe\Forms\GridField\GridFieldFooter;
use SilverStripe\Forms\GridField\GridFieldDetailForm;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use Symbiote\GridFieldExtensions\GridFieldTitleHeader;
use SilverStripe\Forms\GridField\GridFieldToolbarHeader;
use SilverStripe\Forms\GridField\GridFieldSortableHeader;
use Mailgun\Model\Domain\IndexResponse as DomainIndexResponse;

/**
 * Allow you to see messages sent through the api key used to send messages
 *
 * @author LeKoala <thomas@lekoala.be>
 */
class MailgunAdmin extends LeftAndMain implements PermissionProvider
{

    const MESSAGE_CACHE_MINUTES = 5;
    const WEBHOOK_CACHE_MINUTES = 1440; // 1 day
    const SENDINGDOMAIN_CACHE_MINUTES = 1440; // 1 day

    private static $menu_title = "Mailgun";
    private static $url_segment = "mailgun";
    private static $menu_icon = "mailgun/images/mailgun-icon.png";
    private static $url_rule = '/$Action/$ID/$OtherID';
    private static $allowed_actions = [
        'settings',
        'SearchForm',
        'doSearch',
        "doInstallHook",
        "doUninstallHook",
        "doInstallDomain",
        "doUninstallDomain",
    ];

    /**
     * @var boolean
     */
    private static $cache_enabled = true;

    /**
     * @var Exception
     */
    protected $lastException;

    /**
     * @var ViewableData
     */
    protected $currentMessage;

    /**
     * Inject public dependencies into the controller
     *
     * @var array
     */
    private static $dependencies = [
        'logger' => '%$Psr\Log\LoggerInterface',
        'cache' => '%$Psr\SimpleCache\CacheInterface.mailgun', // see _config/cache.yml
    ];

    /**
     * @var Psr\Log\LoggerInterface
     */
    public $logger;

    /**
     * @var Psr\SimpleCache\CacheInterface
     */
    public $cache;

    public function init()
    {
        parent::init();

        if (isset($_GET['refresh'])) {
            $this->getCache()->clear();
        }
    }

    public function index($request)
    {
        return parent::index($request);
    }

    public function settings($request)
    {
        return parent::index($request);
    }

    /**
     * @return Session
     */
    public function getSession()
    {
        return $this->getRequest()->getSession();
    }

    /**
     * Returns a GridField of messages
     * @return CMSForm
     */
    public function getEditForm($id = null, $fields = null)
    {
        if (!$id) {
            $id = $this->currentPageID();
        }

        $form = parent::getEditForm($id);

        $record = $this->getRecord($id);

        // Check if this record is viewable
        if ($record && !$record->canView()) {
            $response = Security::permissionFailure($this);
            $this->setResponse($response);
            return null;
        }

        // Build gridfield
        $messageListConfig = GridFieldConfig::create()->addComponents(
            new GridFieldSortableHeader(),
            new GridFieldDataColumns(),
            new GridFieldFooter()
        );

        $messages = $this->Messages();
        if (is_string($messages)) {
            // The api returned an error
            $messagesList = new LiteralField("MessageAlert", $this->MessageHelper($messages, 'bad'));
        } else {
            $messagesList = GridField::create(
                'Messages',
                false,
                $messages,
                $messageListConfig
            )->addExtraClass("messages_grid");

            $columns = $messageListConfig->getComponentByType(GridFieldDataColumns::class);
            $columns->setDisplayFields([
                'event_id' => _t('MailgunAdmin.EventTransmissionId', 'Id'),
                'timestamp' => _t('MailgunAdmin.EventDate', 'Date'),
                'type' => _t('MailgunAdmin.EventType', 'Type'),
                'recipient' => _t('MailgunAdmin.EventRecipient', 'Recipient'),
                'subject' => _t('MailgunAdmin.EventSubject', 'Subject'),
                'sender' => _t('MailgunAdmin.EventSender', 'Sender'),
            ]);

            $columns->setFieldFormatting([
                'timestamp' => function ($value, &$item) {
                    return date('Y-m-d H:i:s', $value);
                },
            ]);

            // Validator setup
            $validator = null;
            if ($record && method_exists($record, 'getValidator')) {
                $validator = $record->getValidator();
            }

            if ($validator) {
                $messageListConfig
                    ->getComponentByType(GridFieldDetailForm::class)
                    ->setValidator($validator);
            }
        }

        // Create tabs
        $messagesTab = new Tab(
            'Messages',
            _t('MailgunAdmin.Messages', 'Messages'),
            $this->SearchFields(),
            $messagesList,
            // necessary for tree node selection in LeftAndMain.EditForm.js
            new HiddenField('ID', false, 0)
        );

        $fields = new FieldList([
            $root = new TabSet('Root', $messagesTab)
        ]);

        if ($this->CanConfigureApi()) {
            $settingsTab = new Tab('Settings', _t('MailgunAdmin.Settings', 'Settings'));

            $domainTabData = $this->DomainTab();
            $settingsTab->push($domainTabData);

            $webhookTabData = $this->WebhookTab();
            $settingsTab->push($webhookTabData);

            // Add a refresh button
            $refreshButton = new LiteralField('RefreshButton', $this->ButtonHelper(
                $this->Link() . '?refresh=true',
                _t('MailgunAdmin.REFRESH', 'Force data refresh from the API')
            ));
            $settingsTab->push($refreshButton);

            $fields->addFieldToTab('Root', $settingsTab);
        }

        // Tab nav in CMS is rendered through separate template
        $root->setTemplate('SilverStripe\\Forms\\CMSTabSet');

        // Manage tabs state
        $actionParam = $this->getRequest()->param('Action');
        if ($actionParam == 'setting') {
            $settingsTab->addExtraClass('ui-state-active');
        } elseif ($actionParam == 'messages') {
            $messagesTab->addExtraClass('ui-state-active');
        }

        $actions = new FieldList();


        // Build replacement form
        $form = Form::create(
            $this,
            'EditForm',
            $fields,
            new FieldList()
        )->setHTMLID('Form_EditForm');
        $form->addExtraClass('cms-edit-form fill-height');
        $form->setTemplate($this->getTemplatesWithSuffix('_EditForm'));
        $form->addExtraClass('ss-tabset cms-tabset ' . $this->BaseCSSClasses());
        $form->setAttribute('data-pjax-fragment', 'CurrentForm');

        $this->extend('updateEditForm', $form);

        return $form;
    }

    /**
     * Get logger
     *
     * @return  Psr\Log\LoggerInterface
     */
    public function getLogger()
    {
        return $this->logger;
    }

    /**
     * Get the cache
     *
     * @return Psr\SimpleCache\CacheInterface
     */
    public function getCache()
    {
        return $this->cache;
    }

    /**
     * @return boolean
     */
    public function getCacheEnabled()
    {
        $v = $this->config()->cache_enabled;
        if ($v === null) {
            $v = self::$cache_enabled;
        }
        return $v;
    }

    /**
     * A simple cache helper
     *
     * @param string $method Using dot notation like events.get
     * @param array $params
     * @param int $expireInSeconds
     * @return array
     */
    protected function getCachedData($method, $params, $expireInSeconds = 60)
    {
        $enabled = $this->getCacheEnabled();
        if ($enabled) {
            $cache = $this->getCache();
            $key = md5($method . '-' . serialize($params));
            $cacheResult = $cache->get($key);
        }
        if ($enabled && $cacheResult) {
            $data = unserialize($cacheResult);
        } else {
            try {
                $client = MailgunHelper::getClient();

                if (!strpos($method, '.') !== false) {
                    throw new Exception("$method should use dot notation");
                }

                // Split dot notation
                $methodParts = explode('.', $method);
                $service = $methodParts[0];
                $realMethod = $methodParts[1];

                if ($service == 'domains') {
                    if (!empty($params)) {
                        $data = $client->$service()->$realMethod($params[0]);
                    } else {
                        $data = $client->$service()->$realMethod();
                    }
                } else {
                    $data = $client->$service()->$realMethod(MailgunHelper::getDomain(), $params);
                }
            } catch (Exception $ex) {
                $this->lastException = $ex;
                $this->getLogger()->debug($ex);
                $data = false;
            }

            //5 minutes cache
            if ($enabled) {
                $cache->set($key, serialize($data), $expireInSeconds);
            }
        }

        return $data;
    }

    /**
     * Values are mixed with default values and formatted for api usage
     *
     * @link https://documentation.mailgun.com/en/latest/api-events.html#query-options
     * @return array
     */
    public function getParams()
    {
        $params = $this->config()->default_search_params;
        if (!$params) {
            $params = [];
        }
        $data = $this->getSession()->get(__class__ . '.Search');
        if (!$data) {
            $data = [];
        }

        $params = array_merge($params, $data);

        // Respect api formats
        if (!empty($params['begin'])) {
            if (!is_int($params['begin'])) {
                $params['begin'] = strtotime(str_replace('/', '-', $params['begin']));
            }
            // Need an end date, default to now
            if (empty($params['end'])) {
                $params['end'] = time();
            }
        }
        if (!empty($params['end'])) {
            if (!is_int($params['end'])) {
                $params['end'] = strtotime(str_replace('/', '-', $params['end']));
            }
        }


        $params = array_filter($params);

        return $params;
    }

    /**
     * Get a raw value for a single param
     * Useful for retrieving values as set by user
     *
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    public function getParam($name, $default = null)
    {
        $data = $this->getSession()->get(__class__ . '.Search');
        if (!$data) {
            return $default;
        }
        return (isset($data[$name]) && strlen($data[$name])) ? $data[$name] : $default;
    }

    public function SearchFields()
    {
        $disabled_filters = $this->config()->disabled_search_filters;
        if (!$disabled_filters) {
            $disabled_filters = [];
        }

        $fields = new CompositeField();
        $fields->push($from = new DateField('params[begin]', _t('MailgunAdmin.DATEFROM', 'From'), $this->getParam('begin')));
        // $from->setConfig('min', date('Y-m-d', strtotime('-10 days')));

        $fields->push(new DateField('params[end]', _t('MailgunAdmin.DATETO', 'To'), $to = $this->getParam('end')));

        if (!in_array('from', $disabled_filters)) {
            $fields->push($friendly_froms = new TextField('params[from]', _t('MailgunAdmin.FRIENDLYFROM', 'Sender'), $this->getParam('from')));
            $friendly_froms->setAttribute('placeholder', 'sender@mail.example.com,other@example.com');
        }

        if (!in_array('to', $disabled_filters)) {
            $fields->push($recipients = new TextField('params[to]', _t('MailgunAdmin.RECIPIENTS', 'Recipients'), $this->getParam('to')));
            $recipients->setAttribute('placeholder', 'recipient@example.com,other@example.com');
        }

        $fields->push(new DropdownField('params[limit]', _t('MailgunAdmin.PERPAGE', 'Number of results'), array(
            100 => 100,
            200 => 200,
            300 => 300,
        ), $this->getParam('limit', 100)));

        foreach ($fields->FieldList() as $field) {
            $field->addExtraClass('no-change-track');
        }

        // This is a ugly hack to allow embedding a form into another form
        $doSearch = new FormAction('doSearch', _t('MailgunAdmin.DOSEARCH', 'Search'));
        $doSearch->addExtraClass('btn-primary');
        $fields->push($doSearch);
        $doSearch->setAttribute('onclick', "jQuery('#Form_SearchForm').append(jQuery('#Form_EditForm input,#Form_EditForm select').clone()).submit();");

        return $fields;
    }

    public function SearchForm()
    {
        $doSearch = new FormAction('doSearch');
        $SearchForm = new Form($this, 'SearchForm', new FieldList(), new FieldList(
            [$doSearch]
        ));
        $SearchForm->setAttribute('style', 'display:none');
        return $SearchForm;
    }

    public function doSearch($data, Form $form)
    {
        $post = $this->getRequest()->postVar('params');
        if (!$post) {
            return $this->redirectBack();
        }
        $params = [];

        $validFields = [];
        foreach ($this->SearchFields()->FieldList()->dataFields() as $field) {
            $validFields[] = str_replace(['params[', ']'], '', $field->getName());
        }

        foreach ($post as $k => $v) {
            if (in_array($k, $validFields)) {
                $params[$k] = $v;
            }
        }

        $this->getSession()->set(__class__ . '.Search', $params);
        $this->getSession()->save($this->getRequest());

        return $this->redirectBack();
    }

    /**
     * List of messages events
     *
     * Messages are cached to avoid hammering the api
     *
     * @return ArrayList|string
     */
    public function Messages()
    {
        $params = $this->getParams();

        /* @var $response EventResponse */
        $response = $this->getCachedData('events.get', $params, 60 * self::MESSAGE_CACHE_MINUTES);

        $messages = [];
        if ($response) {
            $messages = $response->getItems();
        }

        if (empty($messages)) {
            if ($this->lastException) {
                return $this->lastException->getMessage();
            }
            return _t('MailgunAdmin.NO_MESSAGES', 'No messages');
        }

        $list = new ArrayList();
        if ($messages) {
            $mergedMessages = [];
            $allHeaders = [];

            // events may not contain full headers
            foreach ($messages as $message) {
                $realMessage = $message->getMessage();
                //  "message-id" => "20191118104902.1.F9F052E7ED79D36A@sandbox7d650fc2614d4234be80987482afde91.mailgun.org"
                if (!empty($realMessage['headers']['to'])) {
                    $allHeaders[$realMessage['headers']['message-id']] = $realMessage['headers'];
                }
            }

            foreach ($messages as $message) {
                /*
                "headers" => array:4 [▼
                  "to" => ""Some Name" <some@example.com>"
                  "message-id" => "somekindofsandbox.mailgun.org"
                  "from" => "noreply@xomekindofsandbox.mailgun.org"
                  "subject" => "Email subject here"
                ]
                "attachments" => []
                "size" => 110
                */
                $realMessage = $message->getMessage();
                if (empty($realMessage['headers']['to'])) {
                    $headers = $allHeaders[$realMessage['headers']['message-id']];
                } else {
                    $headers = $realMessage['headers'];
                }

                $shortid = substr($realMessage['headers']['message-id'], 0, strpos($realMessage['headers']['message-id'], '@'));
                $m = new ArrayData([
                    'event_id' => $shortid,
                    'timestamp' => $message->getTimestamp(),
                    'type' => $message->getEvent(),
                    'recipient' =>  $headers['to'] ?? '',
                    'subject' => $headers['subject'] ?? '',
                    'sender' => $headers['from'] ?? '',
                ]);
                $list->push($m);
            }
        }

        return $list;
    }

    /**
     * Provides custom permissions to the Security section
     *
     * @return array
     */
    public function providePermissions()
    {
        $title = _t("MailgunAdmin.MENUTITLE", LeftAndMain::menu_title_for_class('Mailgun'));
        return [
            "CMS_ACCESS_MAILGUN" => [
                'name' => _t('MailgunAdmin.ACCESS', "Access to '{title}' section", ['title' => $title]),
                'category' => _t('Permission.CMS_ACCESS_CATEGORY', 'CMS Access'),
                'help' => _t(
                    'MailgunAdmin.ACCESS_HELP',
                    'Allow use of Mailgun admin section'
                )
            ],
        ];
    }

    /**
     * Message helper
     *
     * @param string $message
     * @param string $status
     * @return string
     */
    protected function MessageHelper($message, $status = 'info')
    {
        return '<div class="message ' . $status . '">' . $message . '</div>';
    }

    /**
     * Button helper
     *
     * @param string $link
     * @param string $text
     * @param boolean $confirm
     * @return string
     */
    protected function ButtonHelper($link, $text, $confirm = false)
    {
        $link = '<a class="btn btn-primary" href="' . $link . '"';
        if ($confirm) {
            $link .= ' onclick="return confirm(\'' . _t('MailgunAdmin.CONFIRM_MSG', 'Are you sure?') . '\')"';
        }
        $link .= '>' . $text . '</a>';
        return $link;
    }

    /**
     * A template accessor to check the ADMIN permission
     *
     * @return bool
     */
    public function IsAdmin()
    {
        return Permission::check("ADMIN");
    }

    /**
     * Check the permission for current user
     *
     * @return bool
     */
    public function canView($member = null)
    {
        $mailer = MailgunHelper::getMailer();
        // Another custom mailer has been set
        if (!$mailer instanceof SwiftMailer) {
            return false;
        }
        // Doesn't use the proper transport
        if (!$mailer->getSwiftMailer()->getTransport() instanceof MailgunSwiftTransport) {
            return false;
        }
        return Permission::check("CMS_ACCESS_MAILGUN", 'any', $member);
    }

    /**
     *
     * @return bool
     */
    public function CanConfigureApi()
    {
        return Permission::check('ADMIN') || Director::isDev();
    }

    /**
     * Check if webhook is installed
     *
     * @return array
     */
    public function WebhookInstalled()
    {
        /* @var $response WebhookIndexResponse */
        $response = $this->getCachedData('webhooks.index', null, 60 * self::WEBHOOK_CACHE_MINUTES);
        if (!$response) {
            return false;
        }
        $url = $this->WebhookUrl();
        $dom = $this->getDomain();
        if (strpos($response->getBounceUrl(), $dom)) {
            return true;
        }
        if (strpos($response->getDeliverUrl(), $dom)) {
            return true;
        }
        if (strpos($response->getDropUrl(), $dom)) {
            return true;
        }
        if (strpos($response->getSpamUrl(), $dom)) {
            return true;
        }
        if (strpos($response->getUnsubscribeUrl(), $dom)) {
            return true;
        }
        if (strpos($response->getClickUrl(), $dom)) {
            return true;
        }
        if (strpos($response->getOpenUrl(), $dom)) {
            return true;
        }
        return false;
    }

    /**
     * Hook details for template
     * @return \ArrayData
     */
    public function WebhookDetails()
    {
        $el = $this->WebhookInstalled();
        if ($el) {
            return new ArrayData($el);
        }
    }

    /**
     * Get content of the tab
     *
     * @return FormField
     */
    public function WebhookTab()
    {
        if ($this->WebhookInstalled()) {
            return $this->UninstallHookForm();
        }
        return $this->InstallHookForm();
    }

    /**
     * @return string
     */
    public function WebhookUrl()
    {
        if (self::config()->webhook_base_url) {
            return rtrim(self::config()->webhook_base_url, '/') . '/__mailgun/incoming';
        }
        if (Director::isLive()) {
            return Director::absoluteURL('/__mailgun/incoming');
        }
        $protocol = Director::protocol();
        return $protocol . $this->getDomain() . '/__mailgun/incoming';
    }

    /**
     * Install hook form
     *
     * @return FormField
     */
    public function InstallHookForm()
    {
        $fields = new CompositeField();
        $fields->push(new LiteralField('Info', $this->MessageHelper(
            _t('MailgunAdmin.WebhookNotInstalled', 'Webhook is not installed. It should be configured using the following url {url}. This url must be publicly visible to be used as a hook.', ['url' => $this->WebhookUrl()]),
            'bad'
        )));
        $fields->push(new LiteralField('doInstallHook', $this->ButtonHelper(
            $this->Link('doInstallHook'),
            _t('MailgunAdmin.DOINSTALL_WEBHOOK', 'Install webhook')
        )));
        return $fields;
    }

    public function doInstallHook()
    {
        if (!$this->CanConfigureApi()) {
            return $this->redirectBack();
        }

        $client = MailgunHelper::getClient();

        $url = $this->WebhookUrl();
        $description = SiteConfig::current_site_config()->Title;

        try {
            $types = self::config()->webhook_events;
            if (!empty($types)) {
                foreach ($types as $type) {
                    $client->webhooks()->create(MailgunHelper::getDomain(), $type, $url . '?type=' . $type);
                }
            }
            $this->getCache()->clear();
        } catch (Exception $ex) {
            $this->getLogger()->debug($ex);
        }

        return $this->redirectBack();
    }

    /**
     * Uninstall hook form
     *
     * @return FormField
     */
    public function UninstallHookForm()
    {
        $fields = new CompositeField();
        $fields->push(new LiteralField('Info', $this->MessageHelper(
            _t('MailgunAdmin.WebhookInstalled', 'Webhook is installed and accessible at the following url {url}.', ['url' => $this->WebhookUrl()]),
            'good'
        )));
        $fields->push(new LiteralField('doUninstallHook', $this->ButtonHelper(
            $this->Link('doUninstallHook'),
            _t('MailgunAdmin.DOUNINSTALL_WEBHOOK', 'Uninstall webhook'),
            true
        )));
        return $fields;
    }

    public function doUninstallHook($data, Form $form)
    {
        if (!$this->CanConfigureApi()) {
            return $this->redirectBack();
        }

        $client = MailgunHelper::getClient();

        try {
            $response = $client->webhooks()->index(MailgunHelper::getDomain());
            if ($response) {
                if ($response->getBounceUrl()) {
                    $client->webhooks()->delete(MailgunHelper::getDomain(), 'bounce');
                }
                if ($response->getDeliverUrl()) {
                    $client->webhooks()->delete(MailgunHelper::getDomain(), 'deliver');
                }
                if ($response->getDropUrl()) {
                    $client->webhooks()->delete(MailgunHelper::getDomain(), 'drop');
                }
                if ($response->getSpamUrl()) {
                    $client->webhooks()->delete(MailgunHelper::getDomain(), 'spam');
                }
                if ($response->getUnsubscribeUrl()) {
                    $client->webhooks()->delete(MailgunHelper::getDomain(), 'unsubscribe');
                }
                if ($response->getClickUrl()) {
                    $client->webhooks()->delete(MailgunHelper::getDomain(), 'clicked');
                }
                if ($response->getOpenUrl()) {
                    $client->webhooks()->delete(MailgunHelper::getDomain(), 'open');
                }
            }
            $this->getCache()->clear();
        } catch (Exception $ex) {
            $this->getLogger()->debug($ex);
        }

        return $this->redirectBack();
    }

    /**
     * Check if sending domain is installed
     *
     * @return array
     */
    public function SendingDomainInstalled()
    {
        $client = MailgunHelper::getClient();

        /* @var $response DomainIndexResponse */
        $response = $this->getCachedData('domains.index', $this->getDomain(), 60 * self::SENDINGDOMAIN_CACHE_MINUTES);

        $domains = $response->getDomains();
        $defaultDomain = $this->getDomain();

        foreach ($domains as $domain) {
            if ($domain->getName() == $defaultDomain) {
                return true;
            }
        }
        return false;
    }

    /**
     * Trigger request to check if sending domain is verified
     *
     * @return array
     */
    public function VerifySendingDomain()
    {
        $client = MailgunHelper::getClient();

        $host = $this->getDomain();

        $verification = $client->verifySendingDomain($host);

        if (empty($verification)) {
            return false;
        }
        return $verification;
    }

    /**
     * Get content of the tab
     *
     * @return FormField
     */
    public function DomainTab()
    {
        $defaultDomain = $this->getDomain();
        $defaultDomainInfos = null;

        /* @var $response DomainIndexResponse */
        $response = $this->getCachedData('domains.index', null, 60 * self::SENDINGDOMAIN_CACHE_MINUTES);
        $domains = [];
        if ($response) {
            $domains = $response->getDomains();
        }

        /*
        0 => Domain {#2919 ▼
            -createdAt: DateTimeImmutable @1573661800 {#2921 ▶}
            -smtpLogin: "postmaster@sandbox.mailgun.org"
            -name: "sandbox.mailgun.org"
            -smtpPassword: "some-pass-word"
            -wildcard: false
            -spamAction: "disabled"
            -state: "active"
          }
        */

        $fields = new CompositeField();

        $list = new ArrayList();
        if ($domains) {
            foreach ($domains as $domain) {
                $showResponse = $this->getCachedData('domains.show', [$domain->getName()], 60 * self::SENDINGDOMAIN_CACHE_MINUTES);

                /*
                 "sending_dns_records": [
                    {
                    "record_type": "TXT",
                    "valid": "valid",
                    "name": "domain.com",
                    "value": "v=spf1 include:mailgun.org ~all"
                    },
                    {
                    "record_type": "TXT",
                    "valid": "valid",
                    "name": "domain.com",
                    "value": "k=rsa; p=MIGfMA0GCSqGSIb3DQEBAQUA...."
                    },
                    {
                    "record_type": "CNAME",
                    "valid": "valid",
                    "name": "email.domain.com",
                    "value": "mailgun.org"
                    }
                ]
                */

                $dnsRecords = $showResponse->getOutboundDNSRecords();

                $spfOk = false;
                $dkimOk = false;

                foreach ($dnsRecords as $dnsRecord) {
                    $value = $dnsRecord->getValue();
                    if (strpos($value, 'v=spf1') !== false) {
                        $spfOk = $dnsRecord->isValid();
                    }
                    if (strpos($value, 'k=rsa') !== false) {
                        $dkimOk = $dnsRecord->isValid();
                    }
                }

                $list->push(new ArrayData([
                    'Domain' => $domain->getName(),
                    'SPF' => $spfOk,
                    'DKIM' => $dkimOk,
                    'Verified' => ($domain->getState() == 'active') ? true : false,
                ]));

                if ($domain->getName() == $defaultDomain) {
                    $defaultDomainInfos = $domain;
                }
            }
        }

        $config = GridFieldConfig::create();
        $config->addComponent(new GridFieldToolbarHeader());
        $config->addComponent(new GridFieldTitleHeader());
        $config->addComponent($columns = new GridFieldDataColumns());
        $columns->setDisplayFields(ArrayLib::valuekey(['Domain', 'SPF', 'DKIM', 'Verified']));
        $domainsList = new GridField('SendingDomains', _t('MailgunAdmin.ALL_SENDING_DOMAINS', 'Configured sending domains'), $list, $config);
        $domainsList->addExtraClass('mb-2');
        $fields->push($domainsList);

        if (!$defaultDomainInfos) {
            $this->InstallDomainForm($fields);
        } else {
            $this->UninstallDomainForm($fields);
        }

        return $fields;
    }

    /**
     * @return string
     */
    public function InboundUrl()
    {
        $subdomain = self::config()->inbound_subdomain;
        $domain = $this->getDomain();
        if ($domain) {
            return $subdomain . '.' . $domain;
        }
        return false;
    }

    /**
     * Get domain name from current host
     *
     * @return boolean|string
     */
    public function getDomainFromHost()
    {
        $base = Environment::getEnv('SS_BASE_URL');
        if (!$base) {
            $base = Director::protocolAndHost();
        }
        $host = parse_url($base, PHP_URL_HOST);
        $hostParts = explode('.', $host);
        $parts = count($hostParts);
        if ($parts < 2) {
            return false;
        }
        $domain = $hostParts[$parts - 2] . "." . $hostParts[$parts - 1];
        return $domain;
    }

    /**
     * Get domain from admin email
     *
     * @return boolean|string
     */
    public function getDomainFromEmail()
    {
        $email = MailgunHelper::resolveDefaultFromEmail(null, false);
        if ($email) {
            $domain = substr(strrchr($email, "@"), 1);
            return $domain;
        }
        return false;
    }

    /**
     * Get domain
     *
     * @return boolean|string
     */
    public function getDomain()
    {
        $domain = $this->getDomainFromEmail();
        if (!$domain) {
            return $this->getDomainFromHost();
        }
        return $domain;
    }

    /**
     * Install domain form
     *
     * @param CompositeField $fieldsd
     * @return FormField
     */
    public function InstallDomainForm(CompositeField $fields)
    {
        $host = $this->getDomain();

        $fields->push(new LiteralField('Info', $this->MessageHelper(
            _t('MailgunAdmin.DomainNotInstalled', 'Default sending domain {domain} is not installed.', ['domain' => $host]),
            "bad"
        )));
        $fields->push(new LiteralField('doInstallDomain', $this->ButtonHelper(
            $this->Link('doInstallDomain'),
            _t('MailgunAdmin.DOINSTALLDOMAIN', 'Install domain')
        )));
    }

    public function doInstallDomain()
    {
        if (!$this->CanConfigureApi()) {
            return $this->redirectBack();
        }

        $client = MailgunHelper::getClient();

        $domain = $this->getDomain();

        if (!$domain) {
            return $this->redirectBack();
        }

        try {
            $client->domains()->create($domain);
            $this->getCache()->clear();
        } catch (Exception $ex) {
            $this->getLogger()->debug($ex);
        }

        return $this->redirectBack();
    }

    /**
     * Uninstall domain form
     *
     * @param CompositeField $fieldsd
     * @return FormField
     */
    public function UninstallDomainForm(CompositeField $fields)
    {
        $domainInfos = $this->SendingDomainInstalled();

        $domain = $this->getDomain();

        if ($domainInfos && $domainInfos->getState() == 'active') {
            $fields->push(new LiteralField('Info', $this->MessageHelper(
                _t('MailgunAdmin.DomainInstalled', 'Default domain {domain} is installed.', ['domain' => $domain]),
                'good'
            )));
        } else {
            $fields->push(new LiteralField('Info', $this->MessageHelper(
                _t('MailgunAdmin.DomainInstalledBut', 'Default domain {domain} is installed, but is not properly configured.'),
                'warning'
            )));
        }
        $fields->push(new LiteralField('doUninstallHook', $this->ButtonHelper(
            $this->Link('doUninstallHook'),
            _t('MailgunAdmin.DOUNINSTALLDOMAIN', 'Uninstall domain'),
            true
        )));
    }

    public function doUninstallDomain($data, Form $form)
    {
        if (!$this->CanConfigureApi()) {
            return $this->redirectBack();
        }

        $client = MailgunHelper::getClient();

        $domain = $this->getDomain();

        if (!$domain) {
            return $this->redirectBack();
        }

        try {
            $el = $this->SendingDomainInstalled();
            if ($el) {
                $client->domains()->delete($domain);
            }
            $this->getCache()->clear();
        } catch (Exception $ex) {
            $this->getLogger()->debug($ex);
        }

        return $this->redirectBack();
    }
}
