package main

import (
	"github.com/jonathjan0397/strata-hosting-panel/agent/cmd/strata-agent/app"
	"os"
)

func main() {
	if err := app.Run(); err != nil {
		os.Stderr.WriteString(err.Error() + "\n")
		os.Exit(1)
	}
}
