<?php

namespace Tests\Feature;

use App\Support\EmailLayout;
use App\Support\Outbox;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Emails really leave the outbox (HTML and a plain-text copy). */
class MailTest extends TestCase
{
    public function test_a_queued_email_is_sent_with_html_and_text(): void
    {
        config(['mail.default' => 'array']);
        $id = Outbox::queue('sara@example.com', 'Your order 20001', EmailLayout::wrap('en', 'Your order 20001', '<p>Thank you, <b>Sara</b>.</p>'));
        $this->assertTrue(Outbox::send($id));
        $row = DB::table('mail_outbox')->find($id);
        $this->assertSame('sent', $row->status, (string) $row->last_error);
        $sent = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent);
        $email = $sent[0]->getOriginalMessage();
        $this->assertSame('Your order 20001', $email->getSubject());
        $this->assertStringContainsString('<b>Sara</b>', (string) $email->getHtmlBody());
        $this->assertStringContainsString('Thank you, Sara.', (string) $email->getTextBody());
    }
}
