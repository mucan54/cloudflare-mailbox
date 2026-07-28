package main

import (
	"encoding/base64"
	"fmt"
	"io"
	"strings"

	"github.com/emersion/go-message/mail"
	"github.com/emersion/go-sasl"
	"github.com/emersion/go-smtp"
)

// smtpBackend accepts authenticated submissions and relays them through the
// Laravel send endpoint (which sends via Cloudflare). It stores nothing.
type smtpBackend struct {
	api *API
}

func (b *smtpBackend) NewSession(_ *smtp.Conn) (smtp.Session, error) {
	return &smtpSession{api: b.api}, nil
}

type smtpSession struct {
	api   *API
	token string
	rcpts []string
}

// PLAIN auth → exchange the mailbox password for an API token.
func (s *smtpSession) AuthMechanisms() []string { return []string{sasl.Plain} }

func (s *smtpSession) Auth(_ string) (sasl.Server, error) {
	return sasl.NewPlainServer(func(_, username, password string) error {
		token, err := s.api.Login(username, password)
		if err != nil {
			return fmt.Errorf("authentication failed")
		}
		s.token = token
		return nil
	}), nil
}

func (s *smtpSession) Mail(_ string, _ *smtp.MailOptions) error { return nil }

func (s *smtpSession) Rcpt(to string, _ *smtp.RcptOptions) error {
	s.rcpts = append(s.rcpts, to)
	return nil
}

func (s *smtpSession) Data(r io.Reader) error {
	if s.token == "" {
		return smtp.ErrAuthRequired
	}

	payload, err := parseSubmission(r, s.rcpts)
	if err != nil {
		return err
	}
	return s.api.Send(s.token, payload)
}

func (s *smtpSession) Reset()        { s.rcpts = nil }
func (s *smtpSession) Logout() error { return nil }

// parseSubmission turns an outgoing MIME message + envelope recipients into a
// SendPayload for the Laravel API.
func parseSubmission(r io.Reader, envelope []string) (SendPayload, error) {
	var p SendPayload

	mr, err := mail.CreateReader(r)
	if err != nil {
		// Not MIME we can parse — still deliver to the envelope with no body.
		p.To = envelope
		return p, nil
	}

	if subj, err := mr.Header.Subject(); err == nil {
		p.Subject = subj
	}

	headerTo := addrs(mr.Header, "To")
	headerCc := addrs(mr.Header, "Cc")
	p.To = headerTo
	p.Cc = headerCc
	if len(p.To) == 0 {
		p.To = envelope
	}
	// Bcc = envelope recipients not visible in To/Cc.
	seen := map[string]bool{}
	for _, a := range append(append([]string{}, headerTo...), headerCc...) {
		seen[strings.ToLower(a)] = true
	}
	for _, a := range envelope {
		if !seen[strings.ToLower(a)] {
			p.Bcc = append(p.Bcc, a)
		}
	}

	for {
		part, err := mr.NextPart()
		if err == io.EOF {
			break
		}
		if err != nil {
			break
		}
		switch h := part.Header.(type) {
		case *mail.InlineHeader:
			ct, _, _ := h.ContentType()
			b, _ := io.ReadAll(part.Body)
			if strings.HasPrefix(ct, "text/html") {
				p.HTML = string(b)
			} else {
				p.Text = string(b)
			}
		case *mail.AttachmentHeader:
			filename, _ := h.Filename()
			ct, _, _ := h.ContentType()
			b, _ := io.ReadAll(part.Body)
			p.Attach = append(p.Attach, SendAttach{
				Filename: filename,
				Type:     ct,
				Content:  base64.StdEncoding.EncodeToString(b),
			})
		}
	}

	if p.Subject == "" {
		p.Subject = "(no subject)"
	}
	return p, nil
}

func addrs(h mail.Header, key string) []string {
	list, err := h.AddressList(key)
	if err != nil {
		return nil
	}
	out := make([]string, 0, len(list))
	for _, a := range list {
		out = append(out, a.Address)
	}
	return out
}
