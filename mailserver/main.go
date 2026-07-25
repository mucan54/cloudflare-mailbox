// Optional native-mail bridge for the Cloudflare Mailbox.
//
// This is a standalone, OPTIONAL service. The Laravel app runs perfectly well
// without it. Run this only if you want to use the mailbox from a native mail
// client (Apple Mail, Outlook, Thunderbird) over IMAP + SMTP. It stores nothing
// and holds no database connection — every action is proxied to the Laravel
// mailbox API.
package main

import (
	"crypto/tls"
	"log"
	"os"
	"time"

	"github.com/emersion/go-smtp"
)

func env(key, def string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return def
}

func main() {
	api := NewAPI(env("LARAVEL_API_URL", "http://localhost"))

	var tlsConfig *tls.Config
	if cert, key := os.Getenv("TLS_CERT"), os.Getenv("TLS_KEY"); cert != "" && key != "" {
		pair, err := tls.LoadX509KeyPair(cert, key)
		if err != nil {
			log.Fatalf("tls: %v", err)
		}
		tlsConfig = &tls.Config{Certificates: []tls.Certificate{pair}}
	}

	// --- SMTP submission (587 STARTTLS) ---
	sserv := smtp.NewServer(&smtpBackend{api: api})
	sserv.Addr = env("SMTP_ADDR", ":587")
	sserv.Domain = env("MAIL_DOMAIN", "localhost")
	sserv.ReadTimeout = 60 * time.Second
	sserv.WriteTimeout = 60 * time.Second
	sserv.MaxMessageBytes = 25 * 1024 * 1024
	sserv.MaxRecipients = 50
	sserv.TLSConfig = tlsConfig
	sserv.AllowInsecureAuth = tlsConfig == nil // require STARTTLS in production

	go func() {
		log.Printf("SMTP submission listening on %s", sserv.Addr)
		if err := sserv.ListenAndServe(); err != nil {
			log.Fatalf("smtp: %v", err)
		}
	}()

	// --- IMAP (993 implicit TLS, or 143 without TLS) ---
	go func() {
		addr := env("IMAP_ADDR", ":993")
		log.Printf("IMAP listening on %s", addr)
		if err := serveIMAP(api, addr, tlsConfig); err != nil {
			log.Fatalf("imap: %v", err)
		}
	}()

	select {}
}
