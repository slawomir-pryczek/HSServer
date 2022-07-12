package hscommon

import (
	"strings"
)

func _prefix_gen(s string, to_len int, with string) string {

	if len(s) >= to_len {
		return s
	}

	_r := to_len - len(s)
	if len(with) > 1 {
		_r = (_r / len(with)) + 1
	}
	return strings.Repeat(with, _r)[0:_r]
}

func StrPrefix(s string, to_len int, with string) string {
	return _prefix_gen(s, to_len, with) + s
}

func StrPostfix(s string, to_len int, with string) string {
	return s + _prefix_gen(s, to_len, with)
}

func StrPrefixHTML(s string, to_len int, with string) string {
	html_len := len(s) - StrRealLen(s)
	return _prefix_gen(s, to_len+html_len, with) + s
}

func StrPostfixHTML(s string, to_len int, with string) string {
	html_len := len(s) - StrRealLen(s)
	return s + _prefix_gen(s, to_len+html_len, with)
}

func StrRealLen(s string) int {

	htmlTagStart := '<'
	htmlTagEnd := '>'

	sr := []rune(s)
	count := 0
	should_count := true
	last_char := sr[0]
	for _, c := range []rune(sr) {
		if c == htmlTagStart {
			should_count = false
		}
		if c == htmlTagEnd {
			should_count = true
			last_char = c
			continue
		}
		if should_count && (c != ' ' || last_char != ' ') {
			count++
		}

		last_char = c
	}
	return count
}

func StrMessage(m string, is_ok bool) string {
	if is_ok {
		return "<span style='color: #449944; font-family: monospace'> <b>⬤</b> " + m + "</span>"
	} else {
		return "<span style='color: #dd4444; font-family: monospace'> <b>⮿</b> " + m + "</span>"
	}
}