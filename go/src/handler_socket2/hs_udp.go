package handler_socket2

import (
	"bytes"
	"compress/flate"
	"fmt"
	"net"
	"net/url"
	"strings"
	"sync"
	"time"
)

type udpRequest struct {
	req        string
	start_time int64
	end_time   int64
	status     string

	request_no           int
	sub_requests_pending int
}

var udpStatMutex sync.Mutex
var udpStats map[string]*udpRequest

func init() {
	udpStats = make(map[string]*udpRequest)
	init_cleaners(3, 5, func(cleanup []string) []string {

		ret := make([]string, 0)

		// clean stats
		udpStatMutex.Lock()
		for _, v := range cleanup {
			if tmp, ok := udpStats[v]; ok {
				if tmp.status == "F" || tmp.status == "X" {
					delete(udpStats, v)
				} else {
					ret = append(ret, v)
				}
			}
		}
		udpStatMutex.Unlock()

		return ret
	})
}

func udpStatBeginRequest(rec_id string, request_no int) {
	udpStatMutex.Lock()
	udpStats[rec_id] = &udpRequest{start_time: time.Now().UnixNano(), request_no: request_no, status: "O"}
	udpStatMutex.Unlock()
}

func udpStatRequest(rec_id, request string) {
	udpStatMutex.Lock()
	udpStats[rec_id].req = request
	udpStats[rec_id].sub_requests_pending++
	udpStats[rec_id].status = "P"
	udpStatMutex.Unlock()
}

func udpStatFinishRequest(rec_id string, is_ok bool) {

	status := "F"
	if !is_ok {
		status = "X"
	}

	udpStatMutex.Lock()
	udpStats[rec_id].sub_requests_pending--

	fmt.Println("TASK PENDING FOR CLEANUP: ", udpStats[rec_id], is_ok)

	if !is_ok || udpStats[rec_id].sub_requests_pending <= 0 {
		udpStats[rec_id].status = status
		udpStats[rec_id].end_time = time.Now().UnixNano()

		cleaners_insert(rec_id)
	}
	udpStatMutex.Unlock()
}

func GetStatusUDP() string {

	udpStatMutex.Lock()
	defer udpStatMutex.Unlock()

	ret := ""
	for k, v := range udpStats {
		tmp := "<div class='thread_list'>"

		status := v.status
		if status == "P" {
			status = fmt.Sprintf("%s/%d", status, v.sub_requests_pending)
		}

		tmp += fmt.Sprintf("<span>Num. %d - %s</span> - <b>[%s]</b> %s\n", v.request_no, k, status, v.req)
		tmp += "</div>\n"
		ret += tmp
	}

	return ret

}

func startServiceUDP(bindTo string, handler handlerFunc) {

	fmt.Printf("UDP Service starting : %s\n", bindTo)

	udpAddr, err := net.ResolveUDPAddr("udp", bindTo)
	if err != nil {
		fmt.Printf("Error resolving address: %s, %s\n", bindTo, err)
		return
	}
	fmt.Println(udpAddr)
	listener, err := net.ListenUDP("udp", udpAddr)
	if err != nil {
		fmt.Printf("Error listening on UDP address: %s, %s\n", bindTo, err)
		return
	}

	req_no := 0
	source_buffer := make(map[string][]byte)
	func() {

		buffer := make([]byte, 65536)
		for {
			n, udparr, err := listener.ReadFromUDP(buffer)
			if err != nil {
				continue
			}

			key := udparr.String()
			if _, ok := source_buffer[key]; !ok {

				_tmp := make([]byte, n)
				copy(_tmp, buffer[:n])
				source_buffer[key] = _tmp

			} else {
				source_buffer[key] = append(source_buffer[key], buffer[:n]...)
			}

			req_no++
			udpStatBeginRequest(key, req_no)

			message := source_buffer[key]
			for {

				move_forward, msg_body, is_compressed := processRequestData(key, message, handler)

				// error in message, delete all data!
				if move_forward == -1 {
					message = message[:0]
					udpStatFinishRequest(key, false)
					break
				}

				// packet processed correctly, process the message!
				if move_forward > 0 {

					go func(key string, msg_body []byte, is_compressed bool) {
						udpStatRequest(key, string(msg_body))
						runRequest(msg_body, is_compressed, handler)
						udpStatFinishRequest(key, true)
					}(key, msg_body, is_compressed)

					message = message[move_forward:]
					if len(message) == 0 {
						break
					}
				}

				// we need more data!
				if move_forward == 0 {
					break
				}

			}

			if len(message) == 0 {
				delete(source_buffer, key)
			} else {
				source_buffer[key] = message
			}
		}
	}()
}

// return number of bytes we need to move forward
// < -1 error - flush the buffer
// 0 - needs more data to process the request
// > 1 message processed correctly - move forward
func processRequestData(key string, message []byte, handler handlerFunc) (int, []byte, bool) {

	var terminator = []byte("\r\n\r\n")
	const terminator_len = len("\r\n\r\n")

	lenf := bytes.Index(message, terminator)
	if lenf == -1 {
		return 0, nil, false
	}

	// request is terminated - we can start to process it!
	bytes_rec_uncompressed := 0
	is_compressed, required_size, guid := processHeader(message[0:lenf])
	fmt.Println("GUID: ", guid)

	if required_size < 0 {
		return -1, nil, false
	}

	// we also need to account for length header
	required_size += lenf + terminator_len

	// still need more data to arrive?
	if len(message) < required_size {
		return 0, nil, false
	}

	bytes_rec_uncompressed++
	return required_size, message[lenf+terminator_len : required_size], is_compressed
}

func runRequest(message_body []byte, is_compressed bool, handler handlerFunc) {
	// compression support!

	fmt.Println("FROM UDP: ", string(message_body))
	curr_msg := ""
	if is_compressed {

		r := flate.NewReader(bytes.NewReader(message_body))

		buf := new(bytes.Buffer)
		buf.ReadFrom(r)
		curr_msg = buf.String()
		r.Close()

	} else {
		curr_msg = string(message_body)
	}
	// <<

	// process packet - parameters
	params, _ := url.ParseQuery(curr_msg)
	fmt.Print(params)

	params2 := make(map[string]string)
	for _k, _v := range params {
		params2[_k] = implode(",", _v)
	}
	// <<

	data := ""
	action := ""
	action_specified := false
	for {

		action, action_specified = params2["action"]
		if !action_specified || action == "" {
			action = "default"
		}

		data = handler(CreateHSParamsFromMap(params2))

		//fmt.Println(data)
		if !strings.HasPrefix(data, "X-Forward:") {
			break
		}

		var redir_vals url.Values
		var err error
		if redir_vals, err = url.ParseQuery(data[10:]); err != nil {

			data = "X-Forward, wrong redirect" + data
			break
		}

		params2 = make(map[string]string)
		for rvk, _ := range redir_vals {
			params2[strings.TrimLeft(rvk, "?")] = redir_vals.Get(rvk)
		}

		fmt.Println(params2)
	}
}

type stat_cleaner struct {
	ids map[string]bool
	mu  sync.Mutex
}

var sc_mutex sync.Mutex
var item_pos int
var stat_cleaners []stat_cleaner
var numCleaners int

func init_cleaners(num_pieces, second_per_piece int, callback func([]string) []string) {

	numCleaners = num_pieces
	stat_cleaners = make([]stat_cleaner, num_pieces)
	for k, _ := range stat_cleaners {
		stat_cleaners[k] = stat_cleaner{ids: make(map[string]bool)}
	}

	go func() {
		time_last := -1
		for _ = range time.Tick(200 * time.Millisecond) {
			now := int(time.Now().UnixNano() / 1000000000)
			if now == time_last {
				continue
			}

			sc_mutex.Lock()
			item_pos = (now / second_per_piece) % numCleaners
			sc_mutex.Unlock()
			time_last = now

			_cbdata := cleaners_get()
			go func(_cbdata []string) {
				ret := callback(_cbdata)
				/*fmt.Println("====", item_pos)
				fmt.Println(_cbdata)*/

				cleaners_insert(ret...)

			}(_cbdata)
		}

	}()
}

func cleaners_get() []string {
	sc_mutex.Lock()
	pos := item_pos
	sc_mutex.Unlock()

	tmp := &stat_cleaners[(pos+1)%numCleaners]

	tmp.mu.Lock()
	ret := tmp.ids
	tmp.ids = make(map[string]bool)
	tmp.mu.Unlock()

	ret2 := make([]string, 0, len(ret))
	for k, _ := range ret {
		ret2 = append(ret2, k)
	}

	return ret2
}

func cleaners_insert(ids ...string) {
	sc_mutex.Lock()
	pos := item_pos
	sc_mutex.Unlock()

	tmp := &stat_cleaners[pos]
	tmp.mu.Lock()
	for _, id := range ids {
		tmp.ids[id] = true
	}
	tmp.mu.Unlock()
}
