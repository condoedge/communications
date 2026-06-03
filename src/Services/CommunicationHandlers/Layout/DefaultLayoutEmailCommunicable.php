<?php

namespace Condoedge\Communications\Services\CommunicationHandlers\Layout;

use Illuminate\Mail\Mailable;
use Illuminate\Support\Str;

/**
 * The wrapper for email sending of the communication.
 *
 * Deliverability-aware: produces a multipart message (HTML + plain text),
 * an inbox-preview preheader, and — when the host supplies them via $params —
 * RFC 2369 / RFC 8058 unsubscribe headers and an aligned From / Reply-To.
 *
 * All deliverability params are OPTIONAL so existing callers keep working. The
 * host (which owns its verified sending domain) is expected to pass:
 *   - 'from'                => 'no-reply@example.com'   sender aligned with SPF/DKIM/DMARC
 *   - 'from_name'           => 'Example'
 *   - 'reply_to'            => 'support@example.com'
 *   - 'reply_to_name'       => 'Example Support'
 *   - 'unsubscribe_url'     => signed HTTPS URL accepting GET (page) + POST (one-click)
 *   - 'unsubscribe_mailto'  => 'unsubscribe@example.com'
 *   - 'preheader'           => short inbox-preview text (auto-derived from content if absent)
 *
 * This package stays domain-agnostic on purpose: it is shared across projects,
 * so it never hardcodes a brand domain.
 */
class DefaultLayoutEmailCommunicable extends Mailable
{
    public $communication;
    public $params;

    public function __construct($communication, $params = [])
    {
        $this->communication = $communication;
        $this->params = $params;
    }

    public function build()
    {
        $content = $this->communication->getParsedContent($this->params);
        $plainText = $this->toPlainText($content);
        $preheader = $this->params['preheader'] ?? Str::limit($plainText, 120);

        $this->subject($this->communication->getParsedTitle($this->params) ?: $this->communication->subject);

        $this->applySenderOverrides();
        $this->applyUnsubscribeHeaders();

        return $this->markdown('condoedge-comms::emails.communication-layout', [
                'content' => $content,
                'preheader' => $preheader,
            ])
            ->text('condoedge-comms::emails.communication-layout-text', [
                'text' => $plainText,
            ]);
    }

    /**
     * Align the visible sender with the host's verified domain when provided.
     * Sender/SPF/DKIM/DMARC alignment is the single biggest inbox-placement
     * factor, but the canonical domain belongs to the host, not this package.
     */
    protected function applySenderOverrides(): void
    {
        if (!empty($this->params['from'])) {
            $this->from($this->params['from'], $this->params['from_name'] ?? null);
        }

        if (!empty($this->params['reply_to'])) {
            $this->replyTo($this->params['reply_to'], $this->params['reply_to_name'] ?? null);
        }
    }

    /**
     * Add List-Unsubscribe (RFC 2369) and List-Unsubscribe-Post (RFC 8058,
     * one-click) headers. Required by Gmail/Yahoo bulk-sender policies; without
     * them bulk mail is routinely filed under Promotions or Spam.
     */
    protected function applyUnsubscribeHeaders(): void
    {
        $url = $this->params['unsubscribe_url'] ?? null;
        $mailto = $this->params['unsubscribe_mailto'] ?? null;

        if (!$url && !$mailto) {
            return;
        }

        $this->withSymfonyMessage(function ($message) use ($url, $mailto) {
            $headers = $message->getHeaders();

            if ($headers->has('List-Unsubscribe')) {
                return;
            }

            $values = [];
            if ($url) {
                $values[] = '<' . $url . '>';
            }
            if ($mailto) {
                $values[] = '<mailto:' . $mailto . '>';
            }

            $headers->addTextHeader('List-Unsubscribe', implode(', ', $values));

            // One-click unsubscribe only applies to an HTTPS endpoint.
            if ($url) {
                $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            }
        });
    }

    /**
     * Best-effort HTML-to-text conversion for the plain-text part. A text
     * alternative materially improves spam scoring and accessibility.
     */
    protected function toPlainText(string $html): string
    {
        $text = preg_replace('/<\s*(br|\/p|\/div|\/h[1-6]|\/li|\/tr)\s*\/?>/i', "\n", $html);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }
}
