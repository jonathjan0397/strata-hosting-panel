package php

import (
	"fmt"
	"os"
	"path/filepath"
	"text/template"
)

const poolDir = "/etc/php/%s/fpm/pool.d"

var poolTemplate = template.Must(template.New("pool").Parse(`; Strata Panel managed — do not edit manually
[{{.Username}}]
user  = {{.Username}}
group = www-data

listen = /run/php/php{{.PHPVersion}}-fpm-{{.Username}}.sock
listen.owner = www-data
listen.group = www-data
listen.mode  = 0660

pm = ondemand
pm.max_children      = {{.MaxChildren}}
pm.process_idle_timeout = 10s
pm.max_requests      = 500

php_admin_value[error_log]      = /home/{{.Username}}/logs/php_errors.log
php_admin_flag[log_errors]      = on
php_admin_value[upload_tmp_dir] = /home/{{.Username}}/tmp
php_admin_value[open_basedir]   = /var/www/{{.Username}}:/home/{{.Username}}/tmp:/tmp

; per-account php.ini overrides
php_value[upload_max_filesize] = {{.UploadMax}}
php_value[post_max_size]       = {{.PostMax}}
php_value[memory_limit]        = {{.MemoryLimit}}
php_value[max_execution_time]  = {{.MaxExecTime}}
`))

type PoolConfig struct {
	Username    string
	PHPVersion  string
	MaxChildren int
	UploadMax   string
	PostMax     string
	MemoryLimit string
	MaxExecTime int
}

func DefaultPool(username, phpVersion string) PoolConfig {
	return PoolConfig{
		Username:    username,
		PHPVersion:  phpVersion,
		MaxChildren: 5,
		UploadMax:   "64M",
		PostMax:     "64M",
		MemoryLimit: "256M",
		MaxExecTime: 30,
	}
}

func WritePool(cfg PoolConfig) error {
	dir := fmt.Sprintf(poolDir, cfg.PHPVersion)
	if err := os.MkdirAll(dir, 0755); err != nil {
		return err
	}

	path := filepath.Join(dir, cfg.Username+".conf")
	f, err := os.OpenFile(path, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0644)
	if err != nil {
		return err
	}
	defer f.Close()

	return poolTemplate.Execute(f, cfg)
}

func RemovePool(username, phpVersion string) error {
	path := filepath.Join(fmt.Sprintf(poolDir, phpVersion), username+".conf")
	err := os.Remove(path)
	if os.IsNotExist(err) {
		return nil
	}
	return err
}

func PoolPath(username, phpVersion string) string {
	return filepath.Join(fmt.Sprintf(poolDir, phpVersion), username+".conf")
}
