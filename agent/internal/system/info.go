package system

import (
	"bufio"
	"fmt"
	"os"
	"strconv"
	"strings"
)

type MemInfo struct {
	TotalMB     uint64 `json:"total_mb"`
	AvailableMB uint64 `json:"available_mb"`
	UsedMB      uint64 `json:"used_mb"`
	UsedPct     float64 `json:"used_pct"`
}

type DiskInfo struct {
	Path      string  `json:"path"`
	TotalGB   float64 `json:"total_gb"`
	UsedGB    float64 `json:"used_gb"`
	FreeGB    float64 `json:"free_gb"`
	UsedPct   float64 `json:"used_pct"`
}

type LoadAvg struct {
	One     float64 `json:"1m"`
	Five    float64 `json:"5m"`
	Fifteen float64 `json:"15m"`
}

type CPUInfo struct {
	Cores int `json:"cores"`
}

type SystemInfo struct {
	Load    LoadAvg   `json:"load"`
	Memory  MemInfo   `json:"memory"`
	Disks   []DiskInfo `json:"disks"`
	CPU     CPUInfo   `json:"cpu"`
	Uptime  uint64    `json:"uptime_seconds"`
}

func GetInfo() (*SystemInfo, error) {
	load, err := readLoadAvg()
	if err != nil {
		return nil, fmt.Errorf("load: %w", err)
	}

	mem, err := readMemInfo()
	if err != nil {
		return nil, fmt.Errorf("mem: %w", err)
	}

	disks, err := readDiskInfo()
	if err != nil {
		return nil, fmt.Errorf("disk: %w", err)
	}

	cores := readCPUCores()
	uptime, _ := readUptime()

	return &SystemInfo{
		Load:   load,
		Memory: mem,
		Disks:  disks,
		CPU:    CPUInfo{Cores: cores},
		Uptime: uptime,
	}, nil
}

func readLoadAvg() (LoadAvg, error) {
	data, err := os.ReadFile("/proc/loadavg")
	if err != nil {
		return LoadAvg{}, err
	}
	parts := strings.Fields(string(data))
	if len(parts) < 3 {
		return LoadAvg{}, fmt.Errorf("unexpected loadavg format")
	}
	one, _ := strconv.ParseFloat(parts[0], 64)
	five, _ := strconv.ParseFloat(parts[1], 64)
	fifteen, _ := strconv.ParseFloat(parts[2], 64)
	return LoadAvg{One: one, Five: five, Fifteen: fifteen}, nil
}

func readMemInfo() (MemInfo, error) {
	f, err := os.Open("/proc/meminfo")
	if err != nil {
		return MemInfo{}, err
	}
	defer f.Close()

	vals := make(map[string]uint64)
	scanner := bufio.NewScanner(f)
	for scanner.Scan() {
		line := scanner.Text()
		parts := strings.Fields(line)
		if len(parts) < 2 {
			continue
		}
		key := strings.TrimSuffix(parts[0], ":")
		val, _ := strconv.ParseUint(parts[1], 10, 64)
		vals[key] = val // kB
	}

	total := vals["MemTotal"] / 1024
	avail := vals["MemAvailable"] / 1024
	used := total - avail
	var pct float64
	if total > 0 {
		pct = float64(used) / float64(total) * 100
	}

	return MemInfo{
		TotalMB:     total,
		AvailableMB: avail,
		UsedMB:      used,
		UsedPct:     pct,
	}, nil
}

func readDiskInfo() ([]DiskInfo, error) {
	f, err := os.Open("/proc/mounts")
	if err != nil {
		return nil, err
	}
	defer f.Close()

	seen := map[string]bool{}
	var disks []DiskInfo

	scanner := bufio.NewScanner(f)
	for scanner.Scan() {
		fields := strings.Fields(scanner.Text())
		if len(fields) < 3 {
			continue
		}
		device := fields[0]
		mountpoint := fields[1]
		fstype := fields[2]

		// Only physical-ish filesystems
		if !strings.HasPrefix(device, "/dev/") {
			continue
		}
		if fstype == "tmpfs" || fstype == "devtmpfs" {
			continue
		}
		if seen[device] {
			continue
		}
		seen[device] = true

		var stat syscallStatfs
		if err := statfs(mountpoint, &stat); err != nil {
			continue
		}

		total := float64(stat.Blocks*uint64(stat.Bsize)) / (1 << 30)
		free := float64(stat.Bfree*uint64(stat.Bsize)) / (1 << 30)
		used := total - free
		var pct float64
		if total > 0 {
			pct = used / total * 100
		}

		disks = append(disks, DiskInfo{
			Path:    mountpoint,
			TotalGB: round2(total),
			UsedGB:  round2(used),
			FreeGB:  round2(free),
			UsedPct: round2(pct),
		})
	}

	return disks, nil
}

func readCPUCores() int {
	f, err := os.Open("/proc/cpuinfo")
	if err != nil {
		return 1
	}
	defer f.Close()

	count := 0
	scanner := bufio.NewScanner(f)
	for scanner.Scan() {
		if strings.HasPrefix(scanner.Text(), "processor") {
			count++
		}
	}
	if count == 0 {
		return 1
	}
	return count
}

func readUptime() (uint64, error) {
	data, err := os.ReadFile("/proc/uptime")
	if err != nil {
		return 0, err
	}
	parts := strings.Fields(string(data))
	if len(parts) == 0 {
		return 0, nil
	}
	f, err := strconv.ParseFloat(parts[0], 64)
	return uint64(f), err
}

func round2(v float64) float64 {
	return float64(int(v*100)) / 100
}
