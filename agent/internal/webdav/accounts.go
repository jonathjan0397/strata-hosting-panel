package webdav

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"sync"

	"golang.org/x/crypto/bcrypt"
)

const DefaultAccountsFile = "/etc/strata-webdav/accounts.json"

var reUsername = regexp.MustCompile(`^[a-z][a-z0-9_]{1,31}$`)

type Account struct {
	Username     string `json:"username"`
	PasswordHash string `json:"password_hash"`
	HomeDir      string `json:"home_dir"`
	Active       bool   `json:"active"`
}

type Store struct {
	path string
	mu   sync.Mutex
}

func NewStore(path string) *Store {
	if path == "" {
		path = DefaultAccountsFile
	}
	return &Store{path: path}
}

func (s *Store) All() (map[string]Account, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.load()
}

func (s *Store) Upsert(username, password, homeDir string) error {
	if !reUsername.MatchString(username) {
		return fmt.Errorf("invalid WebDAV username: %s", username)
	}
	homeDir = filepath.Clean(homeDir)
	if !strings.HasPrefix(homeDir, "/var/www/") || homeDir == "/var/www" {
		return fmt.Errorf("invalid WebDAV home directory: %s", homeDir)
	}
	if err := os.MkdirAll(homeDir, 0755); err != nil {
		return fmt.Errorf("mkdir %s: %w", homeDir, err)
	}
	hash, err := bcrypt.GenerateFromPassword([]byte(password), bcrypt.DefaultCost)
	if err != nil {
		return err
	}

	s.mu.Lock()
	defer s.mu.Unlock()

	accounts, err := s.load()
	if err != nil {
		return err
	}
	accounts[username] = Account{
		Username:     username,
		PasswordHash: string(hash),
		HomeDir:      homeDir,
		Active:       true,
	}
	return s.save(accounts)
}

func (s *Store) Delete(username string) error {
	if !reUsername.MatchString(username) {
		return fmt.Errorf("invalid WebDAV username: %s", username)
	}

	s.mu.Lock()
	defer s.mu.Unlock()

	accounts, err := s.load()
	if err != nil {
		return err
	}
	delete(accounts, username)
	return s.save(accounts)
}

func (s *Store) Authenticate(username, password string) (Account, bool) {
	accounts, err := s.All()
	if err != nil {
		return Account{}, false
	}
	account, ok := accounts[username]
	if !ok || !account.Active {
		return Account{}, false
	}
	if bcrypt.CompareHashAndPassword([]byte(account.PasswordHash), []byte(password)) != nil {
		return Account{}, false
	}
	return account, true
}

func (s *Store) load() (map[string]Account, error) {
	accounts := map[string]Account{}
	data, err := os.ReadFile(s.path)
	if os.IsNotExist(err) {
		return accounts, nil
	}
	if err != nil {
		return nil, err
	}
	if len(data) == 0 {
		return accounts, nil
	}
	if err := json.Unmarshal(data, &accounts); err != nil {
		return nil, err
	}
	return accounts, nil
}

func (s *Store) save(accounts map[string]Account) error {
	if err := os.MkdirAll(filepath.Dir(s.path), 0700); err != nil {
		return err
	}
	data, err := json.MarshalIndent(accounts, "", "  ")
	if err != nil {
		return err
	}
	tmp := s.path + ".tmp"
	if err := os.WriteFile(tmp, data, 0600); err != nil {
		return err
	}
	if err := os.Rename(tmp, s.path); err != nil {
		return err
	}
	return os.Chmod(s.path, 0600)
}
