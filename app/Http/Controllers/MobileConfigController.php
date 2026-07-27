<?php

namespace App\Http\Controllers;

use App\Models\Mailbox;
use Illuminate\Http\Response;

/**
 * Serves a per-mailbox Apple configuration profile (.mobileconfig) that bundles
 * Mail (IMAP/SMTP), Calendar (CalDAV) and Contacts (CardDAV) into a single
 * one-tap install on iOS/macOS. This is the closest a custom domain can get to
 * Gmail's "add one account, get everything" experience — Apple only bundles
 * automatically for its own hardcoded providers, so we ship the bundle
 * ourselves as a profile.
 *
 * Passwords are intentionally left out: the user types their mailbox password
 * once when the profile installs (PayloadContent without a password prompts for
 * it), so the profile can be served over a plain authenticated link without
 * leaking credentials.
 */
class MobileConfigController extends Controller
{
    public function show(string $mailbox): Response
    {
        $account = Mailbox::where('email', $mailbox)->firstOrFail();

        $email = $account->email;
        $domain = strtolower(substr(strrchr($email, '@'), 1));
        $display = $account->display_name ?: $email;

        $mailHost = 'mail.'.$domain;
        $davHost = (string) config('cloudflare.mail_client.app_host');
        $imapPort = (int) config('cloudflare.mail_client.imap_port', 993);
        $smtpPort = (int) config('cloudflare.mail_client.smtp_port', 587);

        // Stable, deterministic UUIDs so reinstalling updates the same profile
        // instead of stacking duplicates.
        $uuid = fn (string $seed) => $this->uuidFrom($email.'|'.$seed);

        $payloads = [];

        // Mail (only when a bridge host is configured — DAV can run alone).
        if (config('cloudflare.mail_client.server_host')) {
            $payloads[] = $this->mailPayload($uuid('mail'), $display, $email, $mailHost, $imapPort, $smtpPort);
        }

        $payloads[] = $this->calDavPayload($uuid('caldav'), $display, $email, $davHost);
        $payloads[] = $this->cardDavPayload($uuid('carddav'), $display, $email, $davHost);

        $plist = $this->wrap($uuid('profile'), $domain, $email, implode('', $payloads));

        return response($plist, 200, [
            'Content-Type' => 'application/x-apple-aspen-config; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$email.'.mobileconfig"',
        ]);
    }

    private function mailPayload(string $uuid, string $display, string $email, string $host, int $imapPort, int $smtpPort): string
    {
        return $this->dict([
            'PayloadType' => 'com.apple.mail.managed',
            'PayloadVersion' => 1,
            'PayloadIdentifier' => 'dev.mailbox.mail.'.$uuid,
            'PayloadUUID' => $uuid,
            'PayloadDisplayName' => 'Mail — '.$display,
            'EmailAccountType' => 'EmailTypeIMAP',
            'EmailAccountName' => $display,
            'EmailAccountDescription' => $email,
            'EmailAddress' => $email,
            'IncomingMailServerHostName' => $host,
            'IncomingMailServerPortNumber' => $imapPort,
            'IncomingMailServerUseSSL' => true,
            'IncomingMailServerAuthentication' => 'EmailAuthPassword',
            'IncomingMailServerUsername' => $email,
            'OutgoingMailServerHostName' => $host,
            'OutgoingMailServerPortNumber' => $smtpPort,
            'OutgoingMailServerUseSSL' => true,
            'OutgoingMailServerAuthentication' => 'EmailAuthPassword',
            'OutgoingMailServerUsername' => $email,
            'OutgoingPasswordSameAsIncomingPassword' => true,
        ]);
    }

    private function calDavPayload(string $uuid, string $display, string $email, string $host): string
    {
        return $this->dict([
            'PayloadType' => 'com.apple.caldav.account',
            'PayloadVersion' => 1,
            'PayloadIdentifier' => 'dev.mailbox.caldav.'.$uuid,
            'PayloadUUID' => $uuid,
            'PayloadDisplayName' => 'Takvim — '.$display,
            'CalDAVAccountDescription' => $display.' (Takvim)',
            'CalDAVHostName' => $host,
            'CalDAVPort' => 443,
            'CalDAVUseSSL' => true,
            'CalDAVPrincipalURL' => '/dav/principals/'.$email.'/',
            'CalDAVUsername' => $email,
        ]);
    }

    private function cardDavPayload(string $uuid, string $display, string $email, string $host): string
    {
        return $this->dict([
            'PayloadType' => 'com.apple.carddav.account',
            'PayloadVersion' => 1,
            'PayloadIdentifier' => 'dev.mailbox.carddav.'.$uuid,
            'PayloadUUID' => $uuid,
            'PayloadDisplayName' => 'Kişiler — '.$display,
            'CardDAVAccountDescription' => $display.' (Kişiler)',
            'CardDAVHostName' => $host,
            'CardDAVPort' => 443,
            'CardDAVUseSSL' => true,
            'CardDAVPrincipalURL' => '/dav/principals/'.$email.'/',
            'CardDAVUsername' => $email,
        ]);
    }

    /** @param  string  $payloads  Concatenated <dict>…</dict> payload entries. */
    private function wrap(string $uuid, string $domain, string $email, string $payloads): string
    {
        $top = $this->dict([
            'PayloadType' => 'Configuration',
            'PayloadVersion' => 1,
            'PayloadIdentifier' => 'dev.mailbox.'.$domain,
            'PayloadUUID' => $uuid,
            'PayloadDisplayName' => $email,
            'PayloadDescription' => 'E-posta, Takvim ve Kişiler',
            'PayloadOrganization' => $domain,
            'PayloadRemovalDisallowed' => false,
            '__PayloadContent__' => $payloads,
        ]);

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">'."\n"
            .'<plist version="1.0">'.$top.'</plist>';
    }

    /**
     * Render an associative array as an Apple plist <dict>. Bools become
     * <true/>/<false/>, ints <integer>, everything else <string>. The special
     * key `__PayloadContent__` injects a pre-built <array> of sub-payloads.
     *
     * @param  array<string, mixed>  $pairs
     */
    private function dict(array $pairs): string
    {
        $out = '<dict>';
        foreach ($pairs as $key => $value) {
            if ($key === '__PayloadContent__') {
                $out .= '<key>PayloadContent</key><array>'.$value.'</array>';

                continue;
            }
            $out .= '<key>'.htmlspecialchars($key, ENT_XML1).'</key>';
            if (is_bool($value)) {
                $out .= $value ? '<true/>' : '<false/>';
            } elseif (is_int($value)) {
                $out .= '<integer>'.$value.'</integer>';
            } else {
                $out .= '<string>'.htmlspecialchars((string) $value, ENT_XML1).'</string>';
            }
        }

        return $out.'</dict>';
    }

    /** Deterministic RFC 4122-shaped UUID (v5-style) from a seed string. */
    private function uuidFrom(string $seed): string
    {
        $h = md5($seed);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($h, 0, 8),
            substr($h, 8, 4),
            substr($h, 12, 4),
            substr($h, 16, 4),
            substr($h, 20, 12),
        );
    }
}
