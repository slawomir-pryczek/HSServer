package oslimits
package oslimits

import (
	"fmt"
	"os"
	"syscall"
)
































}	return true	}		fmt.Fprintf(os.Stderr, _tmp)		_tmp := fmt.Sprintf("Error Setting Rlimit, requested %d, got %d/%d ", num, rLimit.Cur, rLimit.Max)	if rLimit.Max != uint64(num) || rLimit.Cur != uint64(num) {	}		return false		fmt.Fprintf(os.Stderr, "Error Getting Rlimit "+err.Error())	if err != nil {	err = syscall.Getrlimit(syscall.RLIMIT_NOFILE, &rLimit)	}		return false		fmt.Fprintf(os.Stderr, "Error Setting Rlimit "+err.Error())	if err != nil {	err = syscall.Setrlimit(syscall.RLIMIT_NOFILE, &rLimit)	rLimit.Cur = uint64(num)	rLimit.Max = uint64(num)	}		return false		fmt.Fprintf(os.Stderr, "Error Getting Rlimit "+err.Error())	if err != nil {	err := syscall.Getrlimit(syscall.RLIMIT_NOFILE, &rLimit)	var rLimit syscall.Rlimitfunc SetOpenFilesLimit(num int) bool {