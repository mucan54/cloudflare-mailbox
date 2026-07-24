import PostalMime from "postal-mime";

/**
 * Cloudflare Email Worker — parses each inbound message and forwards it to the
 * Laravel webhook (HMAC-signed). Never throws on webhook failure so mail is
 * not bounced; falls back to forwarding when configured.
 *
 * Config (wrangler vars / secrets, rendered by `php artisan cf:deploy-worker`):
 *   WEBHOOK_URL              e.g. https://mail.example.com/api/cf/incoming
 *   ACCOUNT_ID               Cloudflare account id (sent as X-CF-Account)
 *   WEBHOOK_SECRET           per-account HMAC secret (wrangler secret)
 *   INLINE_ATTACHMENT_MAX    bytes; larger attachments are skipped inline
 *   FALLBACK_FORWARD_TO      optional address used when the webhook is down
 */
export default {
  async email(message, env, ctx) {
    const parsed = await PostalMime.parse(message.raw);

    const inlineMax = parseInt(env.INLINE_ATTACHMENT_MAX ?? "2097152", 10);
    const attachments = (parsed.attachments ?? []).map((a) => {
      const bytes = a.content?.byteLength ?? 0;
      const base = {
        filename: a.filename,
        mimeType: a.mimeType,
        contentId: a.contentId,
        size: bytes,
      };
      if (bytes > 0 && bytes <= inlineMax) {
        base.content = toBase64(a.content);
      } else {
        base.too_large = true; // large attachment: deliver metadata only
      }
      return base;
    });

    const payload = {
      message_id: message.headers.get("Message-ID"),
      in_reply_to: message.headers.get("In-Reply-To"),
      references: (message.headers.get("References") ?? "")
        .split(/\s+/)
        .filter(Boolean),
      envelope_from: message.from,
      envelope_to: message.to,
      subject: parsed.subject,
      text: parsed.text,
      html: parsed.html,
      from: parsed.from,
      to: parsed.to,
      cc: parsed.cc,
      date: parsed.date,
      headers: [...message.headers],
      attachments,
      raw_size: message.rawSize,
    };

    const body = JSON.stringify(payload);
    const ts = `${Date.now()}`;
    const signature = await hmacSha256Hex(env.WEBHOOK_SECRET, `${ts}.${body}`);

    try {
      const res = await fetch(env.WEBHOOK_URL, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-CF-Account": env.ACCOUNT_ID,
          "X-CF-Signature": signature,
          "X-CF-Timestamp": ts,
        },
        body,
      });
      if (!res.ok) throw new Error(`webhook responded ${res.status}`);
    } catch (err) {
      // Do NOT throw — throwing bounces the mail. Fall back to forwarding.
      if (env.FALLBACK_FORWARD_TO) {
        try {
          await message.forward(env.FALLBACK_FORWARD_TO);
        } catch (_) {
          // last resort: swallow so the message is accepted, not rejected
        }
      }
    }
  },
};

async function hmacSha256Hex(secret, data) {
  const key = await crypto.subtle.importKey(
    "raw",
    new TextEncoder().encode(secret),
    { name: "HMAC", hash: "SHA-256" },
    false,
    ["sign"],
  );
  const sig = await crypto.subtle.sign("HMAC", key, new TextEncoder().encode(data));
  return [...new Uint8Array(sig)].map((b) => b.toString(16).padStart(2, "0")).join("");
}

function toBase64(buffer) {
  let binary = "";
  const bytes = new Uint8Array(buffer);
  const chunk = 0x8000;
  for (let i = 0; i < bytes.length; i += chunk) {
    binary += String.fromCharCode(...bytes.subarray(i, i + chunk));
  }
  return btoa(binary);
}
