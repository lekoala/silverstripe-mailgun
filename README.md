# SilverStripe Mailgun module

[![Build Status](https://travis-ci.org/lekoala/silverstripe-mailgun.svg?branch=master)](https://travis-ci.org/lekoala/silverstripe-mailgun)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/lekoala/silverstripe-mailgun/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/lekoala/silverstripe-mailgun/?branch=master)
[![Code Coverage](https://scrutinizer-ci.com/g/lekoala/silverstripe-mailgun/badges/coverage.png?b=master)](https://scrutinizer-ci.com/g/lekoala/silverstripe-mailgun/?branch=master)
[![Build Status](https://scrutinizer-ci.com/g/lekoala/silverstripe-mailgun/badges/build.png?b=master)](https://scrutinizer-ci.com/g/lekoala/silverstripe-mailgun/build-status/master)
[![codecov.io](https://codecov.io/github/lekoala/silverstripe-mailgun/coverage.svg?branch=master)](https://codecov.io/github/lekoala/silverstripe-mailgun?branch=master)

[![Latest Stable Version](https://poser.pugx.org/lekoala/silverstripe-mailgun/version)](https://packagist.org/packages/lekoala/silverstripe-mailgun)
[![Latest Unstable Version](https://poser.pugx.org/lekoala/silverstripe-mailgun/v/unstable)](//packagist.org/packages/lekoala/silverstripe-mailgun)
[![Total Downloads](https://poser.pugx.org/lekoala/silverstripe-mailgun/downloads)](https://packagist.org/packages/lekoala/silverstripe-mailgun)
[![License](https://poser.pugx.org/lekoala/silverstripe-mailgun/license)](https://packagist.org/packages/lekoala/silverstripe-mailgun)
[![Monthly Downloads](https://poser.pugx.org/lekoala/silverstripe-mailgun/d/monthly)](https://packagist.org/packages/lekoala/silverstripe-mailgun)
[![Daily Downloads](https://poser.pugx.org/lekoala/silverstripe-mailgun/d/daily)](https://packagist.org/packages/lekoala/silverstripe-mailgun)

![codecov.io](https://codecov.io/github/lekoala/silverstripe-mailgun/branch.svg?branch=master)

## Setup

Define in your .env file the following variables

	MAILGUN_API_KEY='YOUR_API_KEY_HERE'
	MAILGUN_DOMAIN='example.com'

or by defining the api key in your config.yml

```yaml
LeKoala\Mailgun\MailgunHelper:
    api_key: 'YOUR_API_KEY_HERE'
    domain: 'example.com'
```

This module uses the [official client](https://github.com/mailgun/mailgun-php)
Also make sure to check the [official documentation](https://documentation.mailgun.com/en/latest/index.html)

You can also autoconfigure the module with the following environment variables

    # Will log emails in the temp folders
    MAILGUN_ENABLE_LOGGING=true
    # Will disable sending (useful in development)
	MAILGUN_SENDING_DISABLED=true

By defining the Api Key, the module will register a new transport that will be used to send all emails.

If you're using the [Mailgun EU service](https://documentation.mailgun.com/en/latest/api-intro.html#base-url) you can change the API endpoint

    # Will use https://api.eu.mailgun.net/v3
    MAILGUN_ENDPOINT='https://api.eu.mailgun.net/v3'

## Debug

Create a postbin http://bin.mailgun.net/ and set the following parameters

    MAILGUN_ENDPOINT='http://bin.mailgun.net/XXX'
    MAILGUN_DEBUG=true

Please note that the test suite does not work with postbin because it returns html
response and do not mock api behaviour

## Register the new mailer

If you define the MAILGUN_API_KEY variable, the mailer transport will be automatically registered.

Otherwise, you need to call the following line:

```php
MailgunHelper::registerTransport();
```

## Mailgun integration

This module create a new admin section that allows you to:

- List all messages events and allow searching them
- Have a settings tab to list and configure sending domains and webhook

NOTE : Make sure that you have a valid api key (not a subaccount key) to access
features related to installation of the webhook through the CMS.

## Setting tags or metadata

By using custom headers you can pass parameters to the api by following the
same principle than the SMTP api.

The main way to pass parameters is to add a json encoded string through the
X-Mailgun-Variables header, but you can also use that Mandrill compatiblity layer.

```php
$email = new Email();
$email->setSubject($subject);
$email->setBody($body);
// Through Mandrill compat layer
$email->getSwiftMessage()->getHeaders()->addTextHeader('X-MC-Metadata', json_encode(['FIRST_NAME' => 'Jon Smith']));
// Or use Mailgun
$email->getSwiftMessage()->getHeaders()->addTextHeader('X-Mailgun-Variables', json_encode(['FIRST_NAME' => 'Jon Smith']));
```

## Webhooks

NOT IMPLEMENTED YET

From the Mailgun Admin, you can setup a webhook for your website. This webhook
will be called and MailgunController will take care of handling all events
for you. It is registered under the __mailgun/ route.

By default, MailgunController will do nothing. Feel free to add your own
extensions to MailgunController to define your own rules, like "Send an
email to the admin when a receive a spam complaint".

MailgunController provides the following extension point for all events:
- onAnyEvent

And the following extensions points depending on the type of the event:
- onEngagementEvent
- onGenerationEvent
- onMessageEvent
- onUnsubscribeEvent

You can also inspect the whole payload and the batch id with
- beforeProcessPayload : to check if a payload has been processed
- afterProcessPayload : to mark the payload has been processed or log information

You can test if your extension is working properly by visiting /__mailgun/test
if your site is in dev mode. It will load sample data from the API.

Please ensure that the url for the webhook is properly configured if required
by using the following configuration

```yaml
LeKoala\Mailgun\MailgunAdmin:
    webhook_base_url: 'https://my.domain.com/'
```

You can also define the following environment variable to log all incoming payload into a given
directory. Make sure the directory exists. It is relative to your base folder.

    MAILGUN_WEBHOOK_LOG_DIR='_incoming'

## Preventing spam

- Make sure you have properly configured your [SPF](https://mxtoolbox.com/SPFRecordGenerator.aspx) and DKIM records for your domain.

    mydomain.com   TXT   "v=spf1 include:mailgun.org ~all"

- Create a [DMARC record](https://www.unlocktheinbox.com/dmarcwizard/)

    _dmarc.mydomain.com. 3600 IN TXT "v=DMARC1; p=none; sp=none; rf=afrf; pct=100; ri=86400"

- Leave provide_plain option to true or provide plain content for your emails
- Use [Mail Tester](http://www.mail-tester.com/) to troubleshoot your issues

## Inlining styles

In order to have the best resulst, we use the package pelago\emogrifier to inline styles

If you want to restore default functionnality, use this
```yaml
LeKoala\Mailgun\MailgunHelper:
    inline_styles: false
```

## Todo
- Support multiple domains

## Compatibility
Tested with 4.4 but should work fine on any 4.x

## Maintainer
LeKoala - thomas@lekoala.be
