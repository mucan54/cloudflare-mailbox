package main

import (
	"bytes"
	"crypto/tls"
	"errors"
	"strings"
	"time"

	"github.com/emersion/go-imap"
	"github.com/emersion/go-imap/backend"
	"github.com/emersion/go-imap/backend/backendutil"
	"github.com/emersion/go-imap/server"
	gomessage "github.com/emersion/go-message"
)

const uidValidity = 1

// Folder name mapping between IMAP and the Laravel API.
var folders = map[string]string{
	"INBOX":   "inbox",
	"Sent":    "sent",
	"Trash":   "trash",
	"Starred": "starred",
}

func serveIMAP(api *API, addr string, tlsConfig *tls.Config, allowInsecure bool) error {
	s := server.New(&imapBackend{api: api})
	s.Addr = addr
	s.TLSConfig = tlsConfig
	s.AllowInsecureAuth = allowInsecure

	// Implicit TLS on 993 (self-signed or real cert — always encrypted).
	ln, err := tls.Listen("tcp", addr, tlsConfig)
	if err != nil {
		return err
	}
	return s.Serve(ln)
}

// ---- backend ----

type imapBackend struct{ api *API }

func (b *imapBackend) Login(_ *imap.ConnInfo, username, password string) (backend.User, error) {
	token, err := b.api.Login(username, password)
	if err != nil {
		return nil, errors.New("authentication failed")
	}
	return &imapUser{api: b.api, email: username, token: token}, nil
}

// ---- user ----

type imapUser struct {
	api   *API
	email string
	token string
}

func (u *imapUser) Username() string { return u.email }

func (u *imapUser) ListMailboxes(_ bool) ([]backend.Mailbox, error) {
	names := []string{"INBOX", "Sent", "Trash", "Starred"}
	out := make([]backend.Mailbox, 0, len(names))
	for _, n := range names {
		out = append(out, &imapMailbox{user: u, name: n})
	}
	return out, nil
}

func (u *imapUser) GetMailbox(name string) (backend.Mailbox, error) {
	if _, ok := folders[name]; !ok {
		return nil, errors.New("no such mailbox")
	}
	return &imapMailbox{user: u, name: name}, nil
}

func (u *imapUser) CreateMailbox(string) error         { return errors.New("read-only") }
func (u *imapUser) DeleteMailbox(string) error         { return errors.New("read-only") }
func (u *imapUser) RenameMailbox(string, string) error { return errors.New("read-only") }
func (u *imapUser) Logout() error                      { return nil }

// ---- mailbox ----

type imapMailbox struct {
	user *imapUser
	name string
}

func (m *imapMailbox) Name() string { return m.name }

func (m *imapMailbox) Info() (*imap.MailboxInfo, error) {
	return &imap.MailboxInfo{Delimiter: "/", Name: m.name}, nil
}

func (m *imapMailbox) messages() ([]EmailSummary, error) {
	return m.user.api.ListEmails(m.user.token, folders[m.name])
}

func (m *imapMailbox) Status(items []imap.StatusItem) (*imap.MailboxStatus, error) {
	msgs, err := m.messages()
	if err != nil {
		return nil, err
	}
	status := imap.NewMailboxStatus(m.name, items)
	status.Flags = []string{imap.SeenFlag, imap.FlaggedFlag, imap.DeletedFlag}
	status.PermanentFlags = []string{imap.SeenFlag, imap.FlaggedFlag, imap.DeletedFlag}
	status.UidValidity = uidValidity

	var unseen, maxUID uint32
	for _, e := range msgs {
		if !e.Read {
			unseen++
		}
		if uint32(e.ID) > maxUID {
			maxUID = uint32(e.ID)
		}
	}
	for _, name := range items {
		switch name {
		case imap.StatusMessages:
			status.Messages = uint32(len(msgs))
		case imap.StatusUidNext:
			status.UidNext = maxUID + 1
		case imap.StatusUidValidity:
			status.UidValidity = uidValidity
		case imap.StatusUnseen:
			status.Unseen = unseen
		}
	}
	return status, nil
}

func (m *imapMailbox) SetSubscribed(bool) error { return nil }
func (m *imapMailbox) Check() error             { return nil }

func (m *imapMailbox) ListMessages(uid bool, seqset *imap.SeqSet, items []imap.FetchItem, ch chan<- *imap.Message) error {
	defer close(ch)

	msgs, err := m.messages()
	if err != nil {
		return err
	}

	needsBody := false
	for _, it := range items {
		switch it {
		case imap.FetchRFC822, imap.FetchRFC822Text, imap.FetchRFC822Header:
			needsBody = true
		default:
			s := string(it)
			if strings.HasPrefix(s, "BODY[") || strings.HasPrefix(s, "BODY.PEEK[") {
				needsBody = true
			}
		}
	}

	for i, e := range msgs {
		seqNum := uint32(i + 1)
		id := uint32(e.ID)
		if uid {
			if !seqset.Contains(id) {
				continue
			}
		} else if !seqset.Contains(seqNum) {
			continue
		}

		msg := imap.NewMessage(seqNum, items)
		msg.Uid = id
		msg.Flags = flagsFor(e)
		msg.InternalDate = parseDate(e.Received)

		var raw []byte
		if needsBody {
			if b, err := m.user.api.Raw(m.user.token, e.ID); err == nil {
				raw = b
				msg.Size = uint32(len(b))
			}
		}

		for _, item := range items {
			switch item {
			case imap.FetchEnvelope:
				msg.Envelope = envelopeFor(e)
			case imap.FetchInternalDate:
				msg.InternalDate = parseDate(e.Received)
			case imap.FetchRFC822Size:
				msg.Size = uint32(len(raw))
			case imap.FetchUid:
				msg.Uid = id
			case imap.FetchFlags:
				msg.Flags = flagsFor(e)
			default:
				if len(raw) == 0 {
					continue
				}
				section, err := imap.ParseBodySectionName(item)
				if err != nil {
					continue
				}
				ent, err := gomessage.Read(bytes.NewReader(raw))
				if err != nil {
					continue
				}
				body, err := backendutil.FetchBodySection(ent.Header.Header, ent.Body, section)
				if err == nil {
					msg.Body[section] = body
				}
			}
		}
		ch <- msg
	}
	return nil
}

func (m *imapMailbox) SearchMessages(uid bool, criteria *imap.SearchCriteria) ([]uint32, error) {
	msgs, err := m.messages()
	if err != nil {
		return nil, err
	}
	var ids []uint32
	for i, e := range msgs {
		// Minimal: match unseen/seen flags; everything else matches all.
		if len(criteria.WithoutFlags) > 0 {
			skip := false
			for _, f := range criteria.WithoutFlags {
				if f == imap.SeenFlag && e.Read {
					skip = true
				}
			}
			if skip {
				continue
			}
		}
		if uid {
			ids = append(ids, uint32(e.ID))
		} else {
			ids = append(ids, uint32(i+1))
		}
	}
	return ids, nil
}

func (m *imapMailbox) UpdateMessagesFlags(uid bool, seqset *imap.SeqSet, op imap.FlagsOp, flags []string) error {
	msgs, err := m.messages()
	if err != nil {
		return err
	}
	for i, e := range msgs {
		match := false
		if uid {
			match = seqset.Contains(uint32(e.ID))
		} else {
			match = seqset.Contains(uint32(i + 1))
		}
		if !match {
			continue
		}
		fields := map[string]any{}
		for _, f := range flags {
			switch f {
			case imap.SeenFlag:
				fields["read"] = op != imap.RemoveFlags
			case imap.FlaggedFlag:
				fields["starred"] = op != imap.RemoveFlags
			case imap.DeletedFlag:
				if op != imap.RemoveFlags {
					fields["folder"] = "trash"
				}
			}
		}
		if len(fields) > 0 {
			_ = m.user.api.Patch(m.user.token, e.ID, fields)
		}
	}
	return nil
}

func (m *imapMailbox) CopyMessages(bool, *imap.SeqSet, string) error {
	return errors.New("unsupported")
}
func (m *imapMailbox) CreateMessage([]string, time.Time, imap.Literal) error {
	return errors.New("read-only")
}
func (m *imapMailbox) Expunge() error { return nil }

// ---- helpers ----

func flagsFor(e EmailSummary) []string {
	var f []string
	if e.Read {
		f = append(f, imap.SeenFlag)
	}
	if e.Starred {
		f = append(f, imap.FlaggedFlag)
	}
	if e.Folder == "trash" {
		f = append(f, imap.DeletedFlag)
	}
	return f
}

func envelopeFor(e EmailSummary) *imap.Envelope {
	env := &imap.Envelope{Subject: e.Subject, Date: parseDate(e.Received)}
	if e.From != "" {
		env.From = []*imap.Address{addr(e.From)}
		env.Sender = env.From
		env.ReplyTo = env.From
	}
	if e.To != "" {
		env.To = []*imap.Address{addr(e.To)}
	}
	return env
}

func addr(email string) *imap.Address {
	parts := strings.SplitN(email, "@", 2)
	a := &imap.Address{MailboxName: parts[0]}
	if len(parts) == 2 {
		a.HostName = parts[1]
	}
	return a
}

func parseDate(s string) time.Time {
	if s == "" {
		return time.Now()
	}
	if t, err := time.Parse(time.RFC3339, s); err == nil {
		return t
	}
	return time.Now()
}
