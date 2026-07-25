# Optional native-mail (IMAP + SMTP) bridge. Build only if you want to use the
# mailbox from Apple Mail / Outlook / Thunderbird. The Laravel app does not
# depend on this image.
FROM golang:1.24-alpine AS build
WORKDIR /src
COPY go.mod go.sum ./
RUN go mod download
COPY . .
RUN CGO_ENABLED=0 go build -trimpath -o /mailserver .

FROM alpine:3.20
RUN apk add --no-cache ca-certificates
COPY --from=build /mailserver /usr/local/bin/mailserver
# 587 = SMTP submission (STARTTLS), 993 = IMAP over TLS, 8080 = HTTP health
EXPOSE 587 993 8080
# Coolify/Docker health check hits the tiny HTTP /health endpoint (busybox wget).
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
	CMD wget -q -O- http://127.0.0.1:8080/health || exit 1
ENTRYPOINT ["/usr/local/bin/mailserver"]
