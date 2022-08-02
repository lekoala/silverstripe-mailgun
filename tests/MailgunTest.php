<?php

namespace LeKoala\Mailgun\Test;

use SilverStripe\Dev\SapphireTest;
use SilverStripe\Core\Environment;
use SilverStripe\Control\Email\Email;
use LeKoala\Mailgun\MailgunHelper;
use Mailgun\Model\Domain\IndexResponse;
use SilverStripe\Control\Email\Mailer;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Control\Email\SwiftMailer;

/**
 * Test for Mailgun
 *
 * Make sure you have an api key set in yml for this to work
 *
 * @group Mailgun
 */
class MailgunTest extends SapphireTest
{
    /**
     * @var Mailer
     */
    protected $testMailer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testMailer = Injector::inst()->get(Mailer::class);

        // By default, we have a test mailer, restore the original one
        $mailer = new SwiftMailer();
        $swiftMailer = new \Swift_Mailer(new \Swift_MailTransport());
        $mailer->setSwiftMailer($swiftMailer);
        Injector::inst()->registerService($mailer, Mailer::class);

        // Then register our mailer
        MailgunHelper::registerTransport();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        Injector::inst()->registerService($this->testMailer, Mailer::class);
    }

    public function testSetup()
    {
        $inst = MailgunHelper::registerTransport();
        $mailer = MailgunHelper::getMailer();
        $this->assertTrue($inst === $mailer);
    }

    public function testDomains()
    {
        $client = MailgunHelper::getClient();
        $result = $client->domains()->index();

        $this->assertTrue($result instanceof IndexResponse);
        $this->assertNotEmpty($result->getDomains());
    }

    public function testSending()
    {
        $test_to = Environment::getEnv('MAILGUN_TEST_TO');
        $test_from = Environment::getEnv('MAILGUN_TEST_FROM');
        if (!$test_from || !$test_to) {
            $this->markTestIncomplete("You must define tests environement variable: MAILGUN_TEST_TO, MAILGUN_TEST_FROM");
        }

        $this->assertTrue(MailgunHelper::isMailgunMailer(), "Mailgun transport is not used");

        $email = new Email();
        $email->setTo($test_to);
        $email->setSubject('Test email');
        $email->setBody("Body of my email");
        $email->setFrom($test_from);

        // add one headers
        $email->getSwiftMessage()->getHeaders()->addTextHeader('X-Mailgun-Tag', 'test');

        $sent = $email->send();

        $this->assertTrue(!!$sent, "Api returned : ");
    }

    public function testClientSending()
    {
        $test_to = Environment::getEnv('MAILGUN_TEST_TO');
        $test_from = Environment::getEnv('MAILGUN_TEST_FROM');
        if (!$test_from || !$test_to) {
            $this->markTestIncomplete("You must define tests environement variable: MAILGUN_TEST_TO, MAILGUN_TEST_FROM");
        }

        $client = MailgunHelper::getClient();

        $domain = MailgunHelper::getDomain();
        $params = [
            'from' => $test_from,
            'to' => $test_to,
            'subject' => 'Raw client test',
            'text' => "Raw client body",
        ];
        $result = $client->messages()->send($domain, $params);

        $this->assertNotEmpty($result->getId(), $result->getMessage());
    }
}
