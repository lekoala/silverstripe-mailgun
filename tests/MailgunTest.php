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

    protected function setUp()
    {
        parent::setUp();

        $this->testMailer = Injector::inst()->get(Mailer::class);

        // Ensure we have the right mailer
        $mailer = new SwiftMailer();
        $swiftMailer = new \Swift_Mailer(new \Swift_MailTransport());
        $mailer->setSwiftMailer($swiftMailer);
        Injector::inst()->registerService($mailer, Mailer::class);
    }

    protected function tearDown()
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

        $inst = MailgunHelper::registerTransport();

        $email = new Email();
        $email->setTo($test_to);
        $email->setSubject('Test email');
        $email->setBody("Body of my email");
        $email->setFrom($test_from);
        $sent = $email->send();

        $this->assertTrue(!!$sent);
    }
}
