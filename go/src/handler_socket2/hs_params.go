package handler_socket2

import (
	"bytes"
	"encoding/binary"
	"handler_socket2/byteslabs"
	"strconv"
	"strings"
)

type HSParams struct {
	param      map[string][]byte
	porder     []string
	fastreturn []byte

	allocator *byteslabs.Allocator
}

func CreateHSParams() *HSParams {

	ret := HSParams{}
	ret.Cleanup()

	return &ret
}

func CreateHSParamsFromMap(data map[string]string) *HSParams {

	ret := &HSParams{param: make(map[string][]byte), porder: make([]string, 0, len(data))}
	for k, v := range data {
		ret.param[k] = []byte(v)
		ret.porder = append(ret.porder, k)
	}

	return ret
}

func ReadHSParams(message []byte, out_params *HSParams) []byte {

	// read guid from the message
	_mlen := len(message)
	pos := 0

	if pos+2 >= _mlen {
		return nil
	}
	_guid_size := int(binary.LittleEndian.Uint16(message[pos : pos+2]))
	pos += 2

	if pos+_guid_size >= _mlen {
		return nil
	}

	guid := make([]byte, _guid_size)
	copy(guid, message[pos:pos+_guid_size])
	pos += _guid_size

	// Read message parameters
	message = message[pos:]
	pos = 0
	_mlen = len(message)
	if _mlen == 0 {
		return nil
	}

	for pos < len(message) {

		if pos+2 >= _mlen {
			return nil
		}
		_k_s := int(binary.LittleEndian.Uint16(message[pos : pos+2]))
		pos += 2

		if pos+4 >= _mlen {
			return nil
		}
		_v_s := int(binary.LittleEndian.Uint32(message[pos : pos+4]))
		pos += 4

		if pos+_k_s+_v_s > _mlen { // for lop will break, as message could be over now if pos+everything equals mlen
			return nil
		}
		k := message[pos : pos+_k_s]
		pos += _k_s
		v := message[pos : pos+_v_s]
		pos += _v_s

		//vcopy := make([]byte, _v_s)
		//copy(vcopy, v)

		_vstr := string(k)
		out_params.param[_vstr] = v
		out_params.porder = append(out_params.porder, _vstr)
	}

	return guid
}

func (p *HSParams) SetParam(attr string, val string) {
	p.param[attr] = []byte(val)
}

func (p *HSParams) GetParam(attr string, def string) string {

	if val, ok := p.param[attr]; ok {
		return string(val)
	}

	return def
}

// this is not safe because it can use memory shared between requests, so we can't
// keep this after request is over!
func (p *HSParams) GetParamBUnsafe(attr string, def []byte) []byte {

	if val, ok := p.param[attr]; ok {
		return val
	}

	return def
}

func (p *HSParams) GetParamA(attr string, separator string) []string {

	if val, ok := p.param[attr]; ok {
		return strings.Split(string(val), ",")
	}

	return []string{}
}

func (p *HSParams) GetParamIA(attr string) []int {

	ret := make([]int, 0)
	if val, ok := p.param[attr]; ok {
		for _, v := range strings.Split(string(val), ",") {

			if vi, ok := strconv.Atoi(v); ok == nil {
				ret = append(ret, vi)
			}

		}
	}

	return ret
}

func (p *HSParams) getParamInfo() string {

	ret := ""
	for _, k := range p.porder {

		v := p.param[k]

		limit := len(v)
		add := ""
		if len(v) > 500 {
			limit = 500
			add = "..."
		}

		ret += string(k) + "=" + string(v[0:limit]) + add + "&"
	}

	return ret
}

func (p *HSParams) FastReturnB(set []byte) {

	b := p.allocator.Allocate(len(set))
	copy(b, set)
	p.fastreturn = b
}

func (p *HSParams) FastReturnS(set string) {

	if p.allocator == nil {
		p.allocator = byteslabs.MakeAllocator()
	}
	buff := bytes.NewBuffer(p.allocator.Allocate(len(set)))
	buff.WriteString(set)
	p.fastreturn = buff.Bytes()
}

func (p *HSParams) Allocate(size int) []byte {
	if p.allocator == nil {
		p.allocator = byteslabs.MakeAllocator()
	}
	return p.allocator.Allocate(size)
}

func (p *HSParams) Cleanup() {
	if p.allocator != nil {
		p.allocator.Release()
		p.allocator = nil
		p.fastreturn = nil
	}

	p.param = make(map[string][]byte)
	p.porder = make([]string, 0)
}
