package api

import (
	"github.com/go-chi/chi/v5"
)

func Routes() chi.Router {
	r := chi.NewRouter()

	// Health + version
	r.Get("/health", handleHealth)
	r.Get("/version", handleVersion)

	// App installer
	r.Post("/apps/install", handleAppInstall)
	r.Post("/apps/update", handleAppUpdate)
	r.Delete("/apps/uninstall", handleAppUninstall)

	// System info
	r.Get("/system/info", handleSystemInfo)

	// Service management
	r.Get("/services", handleServiceList)
	r.Post("/services/{name}/start", handleServiceStart)
	r.Post("/services/{name}/stop", handleServiceStop)
	r.Post("/services/{name}/restart", handleServiceRestart)
	r.Post("/services/{name}/reload", handleServiceReload)

	// Log viewer
	r.Get("/logs", handleLogList)
	r.Get("/logs/{service}", handleLogRead)

	// Account provisioning
	r.Post("/accounts", handleAccountProvision)
	r.Delete("/accounts/{username}", handleAccountDeprovision)

	// SSH Keys
	r.Get("/accounts/{username}/ssh-keys", handleSshKeyList)
	r.Post("/accounts/{username}/ssh-keys", handleSshKeyAdd)
	r.Delete("/accounts/{username}/ssh-keys/{fingerprint}", handleSshKeyDelete)

	// Nginx vhost management
	r.Post("/nginx/vhost", handleNginxVhostCreate)
	r.Delete("/nginx/vhost/{domain}", handleNginxVhostDelete)
	r.Post("/nginx/reload", handleNginxReload)

	// PHP-FPM pool management
	r.Post("/php/pool", handlePHPPoolCreate)
	r.Delete("/php/pool/{user}", handlePHPPoolDelete)
	r.Put("/php/pool/{user}/version", handlePHPPoolVersionSet)
	r.Put("/php/pool/{user}/settings", handlePHPPoolSettings)

	// SSL
	r.Post("/ssl/issue", handleSSLIssue)
	r.Delete("/ssl/{domain}", handleSSLDelete)

	// Mail domain provisioning
	r.Post("/mail/domain", handleMailDomainProvision)
	r.Delete("/mail/domain/{domain}", handleMailDomainDeprovision)

	// Mailbox management
	r.Post("/mail/mailbox", handleMailboxCreate)
	r.Delete("/mail/mailbox/{email}", handleMailboxDelete)
	r.Put("/mail/mailbox/{email}/password", handleMailboxPassword)

	// Forwarders
	r.Post("/mail/forwarder", handleForwarderCreate)
	r.Delete("/mail/forwarder/{source}", handleForwarderDelete)

	// Autoresponders
	r.Post("/mail/autoresponder", handleAutoresponderSet)
	r.Delete("/mail/autoresponder/{email}", handleAutoresponderDelete)

	// Rspamd
	r.Get("/mail/rspamd/stats", handleRspamdStats)

	// DNS zone + record management (PowerDNS)
	r.Get("/dns/zones", handleDNSListZones)
	r.Post("/dns/zone", handleDNSCreateZone)
	r.Delete("/dns/zone/{domain}", handleDNSDeleteZone)
	r.Get("/dns/zone/{domain}", handleDNSGetZone)
	r.Patch("/dns/zone/{domain}/record", handleDNSUpsertRecord)
	r.Delete("/dns/zone/{domain}/record", handleDNSDeleteRecord)

	// Database management (MariaDB)
	r.Post("/databases", handleDatabaseCreate)
	r.Delete("/databases/{name}", handleDatabaseDelete)
	r.Put("/databases/users/{username}/password", handleDatabasePasswordChange)

	// Database grants
	r.Post("/databases/grant", handleDatabaseGrant)
	r.Delete("/databases/grant", handleDatabaseRevoke)

	// FTP account management (Pure-FTPd)
	r.Post("/ftp/accounts", handleFTPCreate)
	r.Delete("/ftp/accounts/{username}", handleFTPDelete)
	r.Put("/ftp/accounts/{username}/password", handleFTPPassword)

	// Backups
	r.Post("/backups/{username}", handleBackupCreate)
	r.Get("/backups/{username}", handleBackupList)
	r.Delete("/backups/{username}/{filename}", handleBackupDelete)
	r.Get("/backups/{username}/download/{filename}", handleBackupDownload)
	r.Post("/backups/{username}/restore/{filename}", handleBackupRestore)
	r.Post("/backups/{username}/push", handleBackupPush)

	// fail2ban
	r.Get("/fail2ban/status", handleFail2BanStatus)
	r.Post("/fail2ban/unban", handleFail2BanUnban)

	// Firewall (UFW)
	r.Get("/firewall/rules", handleFirewallRules)
	r.Post("/firewall/rules", handleFirewallAddRule)
	r.Delete("/firewall/rules/{number}", handleFirewallDeleteRule)

	// OS updates
	r.Get("/system/updates", handleUpdatesList)
	r.Post("/system/updates", handleUpdatesApply)

	// Custom SSL cert storage
	r.Post("/ssl/store/{domain}", handleSSLStore)

	// Self-upgrade
	r.Post("/agent/upgrade", handleAgentUpgrade)

	// File manager (jailed to /var/www/{username}/)
	r.Get("/files/{username}", handleFileList)
	r.Get("/files/{username}/read", handleFileRead)
	r.Get("/files/{username}/tail", handleFileTail)
	r.Get("/files/{username}/download", handleFileDownload)
	r.Post("/files/{username}/write", handleFileWrite)
	r.Post("/files/{username}/mkdir", handleFileMkdir)
	r.Post("/files/{username}/rename", handleFileRename)
	r.Delete("/files/{username}", handleFileDelete)
	r.Post("/files/{username}/chmod", handleFileChmod)
	r.Post("/files/{username}/compress", handleFileCompress)
	r.Post("/files/{username}/extract", handleFileExtract)
	r.Post("/files/{username}/upload", handleFileUpload)

	return r
}
