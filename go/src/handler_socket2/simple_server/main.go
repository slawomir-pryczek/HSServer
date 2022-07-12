package main

import (
	"handler_socket2"
	"handler_socket2/handle_echo"
	"handler_socket2/handle_profiler"
	"runtime"
	"strings"
)

func main() {

	num_cpu := runtime.NumCPU() * 2
	runtime.GOMAXPROCS(num_cpu)

	// register handlers
	handlers := []handler_socket2.ActionHandler{&handle_echo.HandleEcho{},
		&handle_profiler.HandleProfiler{}}

	if len(handler_socket2.Config().Get("RUN_SERVICES", "")) > 0 && handler_socket2.Config().Get("RUN_SERVICES", "") != "*" {
		_h_modified := []handler_socket2.ActionHandler{}
		_tmp := strings.Split(handler_socket2.Config().Get("RUN_SERVICES", ""), ",")
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
	handler_socket2.StartServer(strings.Split(handler_socket2.Config().Get("BIND_TO", ""), ","))
}
