package main

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"strings"
	"time"
)

// API is a thin client for the Laravel mailbox API. The mail server holds no
// state and no database — every IMAP/SMTP action is proxied to these endpoints.
type API struct {
	BaseURL string
	Client  *http.Client
}

func NewAPI(baseURL string) *API {
	return &API{
		BaseURL: strings.TrimRight(baseURL, "/"),
		Client:  &http.Client{Timeout: 30 * time.Second},
	}
}

// Login exchanges a mailbox's email + password for a Sanctum bearer token.
func (a *API) Login(email, password string) (string, error) {
	body, _ := json.Marshal(map[string]string{"email": email, "password": password})
	req, _ := http.NewRequest(http.MethodPost, a.BaseURL+"/api/mailbox/login", bytes.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")

	res, err := a.Client.Do(req)
	if err != nil {
		return "", err
	}
	defer res.Body.Close()
	if res.StatusCode != http.StatusOK {
		return "", fmt.Errorf("login failed (%d)", res.StatusCode)
	}
	var out struct {
		Token string `json:"token"`
	}
	if err := json.NewDecoder(res.Body).Decode(&out); err != nil {
		return "", err
	}
	if out.Token == "" {
		return "", fmt.Errorf("login returned no token")
	}
	return out.Token, nil
}

// EmailSummary is one row from GET /emails or /sent.
type EmailSummary struct {
	ID       int64  `json:"id"`
	Subject  string `json:"subject"`
	From     string `json:"from_email"`
	To       string `json:"to_email"`
	Snippet  string `json:"snippet"`
	Read     bool   `json:"read"`
	Starred  bool   `json:"starred"`
	Folder   string `json:"folder"`
	Received string `json:"received_at"`
}

type page struct {
	Data []EmailSummary `json:"data"`
}

// ListEmails returns the messages in a mailbox folder (received) or Sent.
func (a *API) ListEmails(token, folder string) ([]EmailSummary, error) {
	url := a.BaseURL + "/api/mailbox/emails?per_page=100&folder=" + folder
	if folder == "sent" {
		url = a.BaseURL + "/api/mailbox/sent?per_page=100"
	}
	req, _ := http.NewRequest(http.MethodGet, url, nil)
	a.auth(req, token)

	res, err := a.Client.Do(req)
	if err != nil {
		return nil, err
	}
	defer res.Body.Close()
	if res.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("list failed (%d)", res.StatusCode)
	}
	var p page
	if err := json.NewDecoder(res.Body).Decode(&p); err != nil {
		return nil, err
	}
	return p.Data, nil
}

// Raw returns the full RFC822 message for a received email.
func (a *API) Raw(token string, id int64) ([]byte, error) {
	req, _ := http.NewRequest(http.MethodGet, fmt.Sprintf("%s/api/mailbox/emails/%d/raw", a.BaseURL, id), nil)
	a.auth(req, token)
	res, err := a.Client.Do(req)
	if err != nil {
		return nil, err
	}
	defer res.Body.Close()
	if res.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("raw failed (%d)", res.StatusCode)
	}
	return io.ReadAll(res.Body)
}

// Patch updates flags/folder on a received email (read, starred, folder).
func (a *API) Patch(token string, id int64, fields map[string]any) error {
	body, _ := json.Marshal(fields)
	req, _ := http.NewRequest(http.MethodPatch, fmt.Sprintf("%s/api/mailbox/emails/%d", a.BaseURL, id), bytes.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	a.auth(req, token)
	res, err := a.Client.Do(req)
	if err != nil {
		return err
	}
	defer res.Body.Close()
	if res.StatusCode >= 300 {
		return fmt.Errorf("patch failed (%d)", res.StatusCode)
	}
	return nil
}

// SendPayload mirrors POST /api/mailbox/send.
type SendPayload struct {
	To      []string     `json:"to"`
	Cc      []string     `json:"cc,omitempty"`
	Bcc     []string     `json:"bcc,omitempty"`
	Subject string       `json:"subject"`
	HTML    string       `json:"html,omitempty"`
	Text    string       `json:"text,omitempty"`
	Attach  []SendAttach `json:"attachments,omitempty"`
}

type SendAttach struct {
	Filename string `json:"filename"`
	Type     string `json:"type"`
	Content  string `json:"content"` // base64
}

// Send relays an outgoing message through the Laravel send endpoint.
func (a *API) Send(token string, p SendPayload) error {
	body, _ := json.Marshal(p)
	req, _ := http.NewRequest(http.MethodPost, a.BaseURL+"/api/mailbox/send", bytes.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")
	a.auth(req, token)
	res, err := a.Client.Do(req)
	if err != nil {
		return err
	}
	defer res.Body.Close()
	if res.StatusCode >= 300 {
		b, _ := io.ReadAll(res.Body)
		return fmt.Errorf("send failed (%d): %s", res.StatusCode, string(b))
	}
	return nil
}

func (a *API) auth(req *http.Request, token string) {
	req.Header.Set("Authorization", "Bearer "+token)
	req.Header.Set("Accept", "application/json")
}
