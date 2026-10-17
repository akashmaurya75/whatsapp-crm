<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Auth\PasswordPolicy;
use App\Auth\Totp;
use App\Core\Crypto;
use App\Core\Logger;
use App\Domain\Contacts\ContactService;
use App\Domain\Contacts\PhoneNormalizer;
use Tests\TestCase;

final class CoreTest extends TestCase
{
    public function testTotpMatchesRfc6238Vector(): void
    {
        // RFC 6238 Appendix B, SHA-1 secret "12345678901234567890", T=59 → 94287082 (last 6 digits)
        $secret = Totp::base32Encode('12345678901234567890');
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $secret);
        $this->assertSame('287082', Totp::code($secret, 59));
        $this->assertSame('081804', Totp::code($secret, 1111111109));
        $this->assertTrue(Totp::verify($secret, '287082', 59));
        $this->assertTrue(Totp::verify($secret, '287082', 89), 'one step of drift tolerated');
        $this->assertFalse(Totp::verify($secret, '287082', 150));
        $this->assertFalse(Totp::verify($secret, 'abcdef', 59));
    }

    public function testCryptoRoundTripAndTamperDetection(): void
    {
        $c = Crypto::encryptArray(['access_token' => 'EAAsecret']);
        $this->assertNotContains('EAAsecret', $c);
        $this->assertSame(['access_token' => 'EAAsecret'], Crypto::decryptArray($c));
        $raw = base64_decode(substr($c, 3));
        $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
        $this->assertThrows(\RuntimeException::class, fn () => Crypto::decrypt('v1:' . base64_encode($raw)));
        $this->assertTrue(Crypto::encrypt('x') !== Crypto::encrypt('x'), 'random nonce');
    }

    public function testPhoneNormalization(): void
    {
        $this->assertSame('+919876543210', PhoneNormalizer::normalize('+91 98765-43210'));
        $this->assertSame('+919876543210', PhoneNormalizer::normalize('09876543210', '91'));
        $this->assertSame('+919876543210', PhoneNormalizer::normalize('9876543210', '91'));
        $this->assertSame('+919876543210', PhoneNormalizer::normalize('0091 98765 43210'));
        $this->assertSame('+447700900123', PhoneNormalizer::normalize('447700900123@c.us', ''));
        $this->assertSame('+14155552671', PhoneNormalizer::normalize('+1 (415) 555-2671'));
        $this->assertSame(null, PhoneNormalizer::normalize('12345'));
        $this->assertSame(null, PhoneNormalizer::normalize('hello'));
        $this->assertSame(null, PhoneNormalizer::normalize('+0123456789'));
    }

    public function testPasswordPolicy(): void
    {
        $this->assertTrue(PasswordPolicy::check('short1') !== null);
        $this->assertTrue(PasswordPolicy::check('onlylettersherelong') !== null);
        $this->assertTrue(PasswordPolicy::check('priya-2026-secret!', 'priya@x.com') !== null, 'contains email name');
        $this->assertSame(null, PasswordPolicy::check('Correct-horse-42'));
        $this->assertTrue(str_starts_with(PasswordPolicy::hash('Correct-horse-42'), '$argon2id$') || str_starts_with(PasswordPolicy::hash('x'), '$2y$'));
    }

    public function testCsvFormulaInjectionIsNeutralised(): void
    {
        $this->assertSame("'=HYPERLINK(\"x\")", ContactService::csvSafe('=HYPERLINK("x")'));
        $this->assertSame("'@SUM(A1)", ContactService::csvSafe('@SUM(A1)'));
        $this->assertSame('+919876543210', ContactService::csvSafe('+919876543210'), 'phone numbers stay intact');
        $this->assertSame('Normal', ContactService::csvSafe('Normal'));
    }

    public function testLoggerRedactsSecrets(): void
    {
        $out = Logger::redact(['access_token' => 'EAA', 'nested' => ['password' => 'p', 'ok' => 1], 'message_id' => 5, 'code' => '123456', 'error_code' => '131026']);
        $this->assertSame('[redacted]', $out['access_token']);
        $this->assertSame('[redacted]', $out['code']);
        $this->assertSame('131026', $out['error_code']);
        $this->assertSame('[redacted]', $out['nested']['password']);
        $this->assertSame(1, $out['nested']['ok']);
        $this->assertSame(5, $out['message_id']);
    }

    public function testWhatsAppFormattingIsXssSafe(): void
    {
        $this->assertSame('Hi <b>Asha</b> &lt;img src=x onerror=alert(1)&gt;', wa_format('Hi *Asha* <img src=x onerror=alert(1)>'));
        $this->assertSame('<b>&lt;script&gt;</b>', wa_format('*<script>*'));
        $this->assertSame('snake_case_name 2*3*4', wa_format('snake_case_name 2*3*4'));
        $this->assertSame('<i>soon</i><br />' . "\n" . '<s>old</s>', wa_format("_soon_\n~old~"));
    }

    public function testRedirectsAreLocalOnly(): void
    {
        $this->assertSame('/', \App\Core\Response::redirect('https://evil.example')->headers['Location']);
        $this->assertSame('/', \App\Core\Response::redirect('//evil.example')->headers['Location']);
        $this->assertSame('/contacts', \App\Core\Response::redirect('/contacts')->headers['Location']);
    }

    public function testQueueMovesExhaustedJobsToFailed(): void
    {
        $app = $this->app();
        $app->queue->push(\Tests\Unit\AlwaysFailsJob::class, []);
        for ($i = 0; $i < 5; $i++) {
            $this->db->run('UPDATE jobs SET available_at = 0');
            $app->queue->work($app, 'default', 't');
        }
        $this->assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM jobs'));
        $this->assertSame(1, (int) $this->db->scalar('SELECT COUNT(*) FROM failed_jobs'));
        $this->assertTrue($app->queue->retryFailed((int) $this->db->scalar('SELECT id FROM failed_jobs')));
        $this->assertSame(1, (int) $this->db->scalar('SELECT COUNT(*) FROM jobs'));
    }
}

final class AlwaysFailsJob implements \App\Core\Job
{
    public function handle(\App\Core\App $app, array $data): void { throw new \RuntimeException('nope'); }
    public function maxAttempts(): int { return 3; }
    public function backoff(int $attempt): int { return 1; }
}
