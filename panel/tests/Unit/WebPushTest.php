<?php

namespace Tests\Unit;

use App\Push\WebPush;
use PHPUnit\Framework\TestCase;
use Tests\Support\PushReceiver;

/**
 * The own Web Push implementation (E-101) against the example of RFC 8291,
 * Appendix A, and the VAPID signature of RFC 8292.
 */
class WebPushTest extends TestCase
{
    public function test_encryption_matches_the_example_of_rfc_8291(): void
    {
        $sender = WebPush::privateKeyPem(
            WebPush::base64UrlDecode('yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw'),
            WebPush::base64UrlDecode('BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8'),
        );

        $body = WebPush::encrypt(
            'When I grow up, I want to be a watermelon',
            'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4',
            'BTBZMqHH6r4Tts7J_aSIgg',
            $sender,
            WebPush::base64UrlDecode('DGv6ra1nlYgDCS1FRnbzlw'),
        );

        $this->assertSame(
            'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN',
            WebPush::base64UrlEncode($body),
        );
    }

    public function test_a_browser_can_decrypt_a_message(): void
    {
        $browser = new PushReceiver;

        $body = WebPush::encrypt('{"title":"Grüße"}', $browser->publicKey(), $browser->authSecret());

        $this->assertSame('{"title":"Grüße"}', $browser->decrypt($body));
        // Every message has its own salt and sender key.
        $this->assertNotSame($body, WebPush::encrypt('{"title":"Grüße"}', $browser->publicKey(), $browser->authSecret()));
    }

    public function test_vapid_header_is_signed_with_the_panel_key(): void
    {
        $keys = WebPush::createVapidKeys();

        $header = WebPush::vapidAuthorization('https://fcm.googleapis.com/fcm/send/abc', $keys['public'], $keys['private'], 'https://panel.example.de', 1_900_000_000);

        $this->assertStringEndsWith('k='.$keys['public'], $header);
        $this->assertSame(
            ['aud' => 'https://fcm.googleapis.com', 'exp' => 1_900_000_000, 'sub' => 'https://panel.example.de'],
            PushReceiver::verifyVapid($header),
        );
    }

    public function test_invalid_subscription_keys_are_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        WebPush::encrypt('x', WebPush::base64UrlEncode("\x05".random_bytes(64)), WebPush::base64UrlEncode(random_bytes(16)));
    }
}
