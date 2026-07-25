package main

import (
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/pem"
	"log"
	"math/big"
	"os"
	"time"
)

// loadTLS returns a TLS config. It uses TLS_CERT/TLS_KEY when provided,
// otherwise it generates an in-memory self-signed certificate so the service
// still starts with TLS (important on Coolify, where mail ports are raw TCP and
// the HTTP proxy cannot terminate TLS for us). Clients will warn on a
// self-signed cert — provide real certs for production.
func loadTLS() *tls.Config {
	cert, key := os.Getenv("TLS_CERT"), os.Getenv("TLS_KEY")
	if cert != "" && key != "" {
		pair, err := tls.LoadX509KeyPair(cert, key)
		if err != nil {
			log.Fatalf("tls: %v", err)
		}
		return &tls.Config{Certificates: []tls.Certificate{pair}}
	}

	pair, err := selfSigned(env("MAIL_DOMAIN", "localhost"))
	if err != nil {
		log.Fatalf("self-signed tls: %v", err)
	}
	log.Printf("WARNING: TLS_CERT/TLS_KEY not set — using a self-signed certificate. " +
		"Mail clients will warn; set real certs for production.")
	return &tls.Config{Certificates: []tls.Certificate{pair}}
}

func selfSigned(host string) (tls.Certificate, error) {
	priv, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		return tls.Certificate{}, err
	}
	tmpl := x509.Certificate{
		SerialNumber:          big.NewInt(time.Now().Unix()),
		Subject:               pkix.Name{CommonName: host},
		NotBefore:             time.Now().Add(-time.Hour),
		NotAfter:              time.Now().AddDate(10, 0, 0),
		KeyUsage:              x509.KeyUsageDigitalSignature | x509.KeyUsageKeyEncipherment,
		ExtKeyUsage:           []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth},
		DNSNames:              []string{host},
		BasicConstraintsValid: true,
	}
	der, err := x509.CreateCertificate(rand.Reader, &tmpl, &tmpl, &priv.PublicKey, priv)
	if err != nil {
		return tls.Certificate{}, err
	}
	keyDER, err := x509.MarshalPKCS8PrivateKey(priv)
	if err != nil {
		return tls.Certificate{}, err
	}
	certPEM := pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der})
	keyPEM := pem.EncodeToMemory(&pem.Block{Type: "PRIVATE KEY", Bytes: keyDER})
	return tls.X509KeyPair(certPEM, keyPEM)
}
