package main

import (
	"handler_socket2"

	"echo"
	"os"
	"profiler"
	"runtime"
	"runtime/pprof"
	"strings"
	"time"
)

func main() {

	num_cpu := runtime.NumCPU() * 2
	runtime.GOMAXPROCS(num_cpu)

	if false {
		f, _ := os.Create("profiler5")
		pprof.StartCPUProfile(f)

		go func() {
			time.Sleep(10 * time.Second)
			pprof.StopCPUProfile()
		}()
	}

	// register handlers
	handlers := []handler_socket2.ActionHandler{&profiler.HandleProfiler{}, &echo.HandleEcho{}}
	if len(handler_socket2.Config.Get("RUN_SERVICES", "")) > 0 && handler_socket2.Config.Get("RUN_SERVICES", "") != "*" {
		_h_modified := []handler_socket2.ActionHandler{}
		_tmp := strings.Split(handler_socket2.Config.Get("RUN_SERVICES", ""), ",")
		supported := make(map[string]bool)
		for _, v := range _tmp {
			supported[strings.Trim(v, "\r\n \t")] = true
		}

		for _, v := range handlers {

			should_enable := false
			for _, action := range handler_socket2.ActionHandler(v).GetActions() {
				if supported[action] {
					should_enable = true
					break
				}
			}

			if should_enable {
				_h_modified = append(_h_modified, v)
			}
		}

		handlers = _h_modified
	}

	// start the server
	handler_socket2.RegisterHandler(handlers...)
	handler_socket2.StartServer(strings.Split(handler_socket2.Config.Get("BIND_TO", ""), ","))

}
