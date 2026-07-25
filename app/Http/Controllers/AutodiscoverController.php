<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Serves mail-client auto-configuration:
 *   - Mozilla/Thunderbird & Apple Mail: /mail/config-v1.1.xml (on
 *     autoconfig.<domain>) and /.well-known/autoconfig/...
 *   - Outlook: POST /autodiscover/autodiscover.xml (POX).
 *
 * All point clients at mail.<domain> for IMAP (SSL) + SMTP (STARTTLS).
 */
class AutodiscoverController extends Controller
{
    private function imapPort(): int
    {
        return (int) config('cloudflare.mail_client.imap_port', 993);
    }

    private function smtpPort(): int
    {
        return (int) config('cloudflare.mail_client.smtp_port', 587);
    }

    /** Resolve the domain from the email param or the request host. */
    private function domainFrom(Request $request, ?string $email = null): string
    {
        $email = $email ?: (string) ($request->query('emailaddress') ?? $request->query('email') ?? '');
        if ($email !== '' && str_contains($email, '@')) {
            return strtolower(substr(strrchr($email, '@'), 1));
        }
        // Fall back to the request host, stripping the autoconfig/autodiscover prefix.
        $host = strtolower((string) $request->getHost());

        return preg_replace('/^(autoconfig|autodiscover)\./', '', $host) ?: $host;
    }

    public function mozilla(Request $request): Response
    {
        $domain = $this->domainFrom($request);
        $mail = 'mail.'.$domain;

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<clientConfig version="1.1">'
            .'<emailProvider id="'.e($domain).'">'
            .'<domain>'.e($domain).'</domain>'
            .'<displayName>'.e($domain).'</displayName>'
            .'<displayShortName>'.e($domain).'</displayShortName>'
            .'<incomingServer type="imap">'
            .'<hostname>'.e($mail).'</hostname>'
            .'<port>'.$this->imapPort().'</port>'
            .'<socketType>SSL</socketType>'
            .'<authentication>password-cleartext</authentication>'
            .'<username>%EMAILADDRESS%</username>'
            .'</incomingServer>'
            .'<outgoingServer type="smtp">'
            .'<hostname>'.e($mail).'</hostname>'
            .'<port>'.$this->smtpPort().'</port>'
            .'<socketType>STARTTLS</socketType>'
            .'<authentication>password-cleartext</authentication>'
            .'<username>%EMAILADDRESS%</username>'
            .'</outgoingServer>'
            .'</emailProvider>'
            .'</clientConfig>';

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    }

    public function outlook(Request $request): Response
    {
        // Outlook POSTs an XML body with <EMailAddress>. Extract it.
        $email = '';
        if (preg_match('/<EMailAddress>(.*?)<\/EMailAddress>/i', (string) $request->getContent(), $m)) {
            $email = trim($m[1]);
        }
        $domain = $this->domainFrom($request, $email);
        $mail = 'mail.'.$domain;
        $login = $email !== '' ? $email : '%EMAILADDRESS%';

        $ns = 'http://schemas.microsoft.com/exchange/autodiscover';
        $xml = '<?xml version="1.0" encoding="utf-8"?>'."\n"
            .'<Autodiscover xmlns="'.$ns.'/responseschema/2006">'
            .'<Response xmlns="'.$ns.'/outlook/responseschema/2006a">'
            .'<Account><AccountType>email</AccountType><Action>settings</Action>'
            .$this->outlookProtocol('IMAP', $mail, $this->imapPort(), $login)
            .$this->outlookProtocol('SMTP', $mail, $this->smtpPort(), $login)
            .'</Account></Response></Autodiscover>';

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    }

    private function outlookProtocol(string $type, string $server, int $port, string $login): string
    {
        return '<Protocol>'
            .'<Type>'.$type.'</Type>'
            .'<Server>'.e($server).'</Server>'
            .'<Port>'.$port.'</Port>'
            .'<DomainRequired>on</DomainRequired>'
            .'<LoginName>'.e($login).'</LoginName>'
            .'<SPA>off</SPA>'
            .'<SSL>on</SSL>'
            .'<AuthRequired>on</AuthRequired>'
            .'</Protocol>';
    }
}
