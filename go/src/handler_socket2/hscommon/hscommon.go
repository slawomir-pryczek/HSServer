package hscommon

import (
	"fmt"
	"math"
	"sort"
	"strconv"
	"strings"
	"sync/atomic"
	"time"
)

// sorting
type ScoredItems struct {
	Item  string
	Score int64
}

type SIArr []ScoredItems

func (si SIArr) Len() int {
	return len(si)
}
func (si SIArr) Less(i, j int) bool {
	return si[i].Score < si[j].Score
}
func (si SIArr) Swap(i, j int) {
	si[i], si[j] = si[j], si[i]
}

type StrScoredItems struct {
	item     string
	score    string
	_usrdata []string
}

type SSIArr []StrScoredItems

func (si SSIArr) Len() int {
	return len(si)
}
func (si SSIArr) Less(i, j int) bool {
	return si[i].score < si[j].score
}
func (si SSIArr) Swap(i, j int) {
	si[i], si[j] = si[j], si[i]
}

func FormatBytes(b uint64) string {

	if b < 0 {
		return "-"
	}

	units := []string{"B", "KB", "MB", "GB", "TB"}

	_unit_num := int(math.Floor(math.Min(math.Log(float64(b))/math.Log(1024), float64(len(units)-1))))
	if _unit_num <= 0 || _unit_num >= len(units) {
		return fmt.Sprintf("%dB", b)
	}

	unit := units[_unit_num]
	_p := uint64(math.Pow(1024, float64(_unit_num)))

	var ba, bb uint64
	if _unit_num > 2 {
		ba = uint64(b * 1000 / uint64(_p))
		bb = ba % 1000
		ba = ba / 1000
		return fmt.Sprintf("%d.%03d%s", ba, bb, unit)
	} else {
		ba = uint64(b * 100 / uint64(_p))
		bb = ba % 100
		ba = ba / 100
	}

	return fmt.Sprintf("%d.%02d%s", ba, bb, unit)
}

func FormatTime(t int) string {
	uptime_str := ""
	ranges := []string{"day", "hour", "minute", "second"}
	div := []int{60 * 60 * 24, 60 * 60, 60, 1}

	for i := 0; i < 4; i++ {

		u_ := t / div[i]
		s_ := ""
		if u_ > 1 {
			s_ = "s"
		}

		if u_ > 0 {
			uptime_str += fmt.Sprintf("%d %s%s ", u_, ranges[i], s_)
			t = t % div[i]
		}
	}

	return uptime_str
}

type tablegen struct {
	header    string
	items     []StrScoredItems
	className string
}

func NewTableGen(header ...string) *tablegen {
	ret := tablegen{}
	ret.header = "<tr><td>" + strings.Join(header, "</td><td>") + "</td></tr>"
	return &ret
}

func (this *tablegen) AddRow(data ...string) {
	_d := "<tr class='##class##'><td>" + strings.Join(data, "</td><td>") + "</td></tr>\n"
	this.items = append(this.items, StrScoredItems{item: _d, _usrdata: data})
}

func (this *tablegen) Render() string {

	data := ""
	for pos, v := range this.items {
		_class := "r" + strconv.Itoa(pos%2)
		data += strings.Replace(v.item, "##class##", _class, 1)
	}

	_class := ""
	if len(this.className) > 0 {
		_class = " class='" + this.className + "'"
	}

	return "<table" + _class + ">\n<thead>" + this.header + "</thead>\n" + "<tbody>" + data + "</tbody>\n</table>"
}

func (this *tablegen) RenderSorted(columns ...int) string {

	for pos, v := range this.items {

		_score := ""
		for _, kcol := range columns {
			if len(v._usrdata) > kcol {
				_score += (v._usrdata[kcol] + "-")
			}
		}

		this.items[pos].score = _score
	}

	// sort the data
	sort.Sort(SSIArr(this.items))

	// we have re-sorted data, now render normally
	return this.Render()
}

func (this *tablegen) RenderSortedRaw(scores []string) string {

	slen := len(scores)
	for pos, _ := range this.items {

		if pos < slen {
			this.items[pos].score = scores[pos]
		}
	}

	// sort the data
	sort.Sort(SSIArr(this.items))

	// we have re-sorted data, now render normally
	return this.Render()
}

func (this *tablegen) SetClass(className string) {
	this.className = className
}

type Buffer struct {
	b []byte
}

func NewBuffer(b []byte) *Buffer {
	return &Buffer{b[:0]}
}
func (this *Buffer) WriteStr(a string) {

	aa := []byte(a)
	start := len(this.b)
	data_len := len(aa)

	copy(this.b[start:start+data_len], aa)
	this.b = this.b[0 : start+data_len]

}

func (this *Buffer) Bytes() []byte {
	return this.b
}

// #############################################################################
// Current timestamp functionality
var unixTS int64 = 0

func init() {

	go func() {

		var __tmp int64 = 0
		var __tmp2 int64 = 0
		for {

			__tmp = time.Now().Unix()
			if __tmp != __tmp2 {
				__tmp2 = __tmp
				atomic.StoreInt64(&unixTS, __tmp)
			}

			time.Sleep(100 * time.Millisecond)
		}

	}()
}

// get current timestamp in seconds
func TSNow() int {

	__tmp := atomic.LoadInt64(&unixTS)

	// maybe the thread isn't running yet
	if __tmp == 0 {
		__tmp = time.Now().Unix()
	}

	return int(__tmp)
}
