<?php

namespace App\Http\Controllers;

use App\Dav\AddressBookBackend;
use App\Dav\AuthBackend;
use App\Dav\CalendarBackend;
use App\Dav\PrincipalBackend;
use Illuminate\Http\Request;
use Sabre\CalDAV;
use Sabre\CardDAV;
use Sabre\DAV;
use Sabre\DAVACL;
use Sabre\HTTP;
use Symfony\Component\HttpFoundation\Response;

/**
 * CalDAV + CardDAV server (sabre/dav) mounted at /dav, backed by the mailbox's
 * events and contacts. Runs over the normal HTTPS app — no extra ports — so it
 * works behind Coolify/Cloudflare and serves both iOS (native) and Android
 * (DAVx5).
 */
class DavController extends Controller
{
    public function handle(Request $request): Response
    {
        $principalBackend = new PrincipalBackend;

        $server = new DAV\Server([
            new DAVACL\PrincipalCollection($principalBackend),
            new CalDAV\CalendarRoot($principalBackend, new CalendarBackend),
            new CardDAV\AddressBookRoot($principalBackend, new AddressBookBackend),
        ]);
        $server->setBaseUri('/dav/');

        $server->addPlugin(new DAV\Auth\Plugin(new AuthBackend));
        $aclPlugin = new DAVACL\Plugin;
        $aclPlugin->allowUnauthenticatedAccess = false;
        $server->addPlugin($aclPlugin);
        $server->addPlugin(new CalDAV\Plugin);
        $server->addPlugin(new CardDAV\Plugin);
        $server->addPlugin(new DAV\Sync\Plugin);

        // Bridge Laravel's request into sabre and capture the response instead
        // of letting sabre write to the output buffer (keeps it testable).
        $sabreRequest = new HTTP\Request(
            $request->getMethod(),
            $request->getRequestUri(),
            $this->headers($request),
            $request->getContent() ?: null,
        );
        $sabreRequest->setBaseUrl('/dav/');
        $sabreResponse = new HTTP\Response;
        $server->httpRequest = $sabreRequest;
        $server->httpResponse = $sabreResponse;

        // invokeMethod (unlike exec) does not catch — convert DAV exceptions to
        // proper error responses (401/404/…) ourselves.
        try {
            $server->invokeMethod($sabreRequest, $sabreResponse, false);
        } catch (\Throwable $e) {
            $this->renderException($server, $sabreResponse, $e);
        }

        return response(
            $sabreResponse->getBodyAsString(),
            $sabreResponse->getStatus(),
            $sabreResponse->getHeaders(),
        );
    }

    private function renderException(DAV\Server $server, HTTP\Response $response, \Throwable $e): void
    {
        try {
            $server->emit('exception', [$e]);
        } catch (\Throwable) {
            // ignore
        }

        $status = $e instanceof DAV\Exception ? $e->getHTTPCode() : 500;

        $dom = new \DOMDocument('1.0', 'utf-8');
        $error = $dom->createElementNS('DAV:', 'd:error');
        $error->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:s', 'http://sabredav.org/ns');
        $dom->appendChild($error);
        $error->appendChild($dom->createElementNS('http://sabredav.org/ns', 's:exception', get_class($e)));
        $error->appendChild($dom->createElementNS('http://sabredav.org/ns', 's:message', htmlspecialchars($e->getMessage(), ENT_NOQUOTES, 'UTF-8')));

        if ($e instanceof DAV\Exception) {
            $e->serialize($server, $error);
            foreach ($e->getHTTPHeaders($server) as $key => $value) {
                $response->setHeader($key, $value); // e.g. WWW-Authenticate
            }
        }

        $response->setStatus($status);
        $response->setHeader('Content-Type', 'application/xml; charset=utf-8');
        $response->setBody($dom->saveXML());
    }

    /**
     * @return array<string, string>
     */
    private function headers(Request $request): array
    {
        $out = [];
        foreach ($request->headers->all() as $key => $values) {
            $out[$key] = implode(', ', $values);
        }

        return $out;
    }
}
