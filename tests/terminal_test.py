"""可选的 POSIX 终端端到端测试，请先运行 composer dump-autoload。"""
import os
import fcntl
import struct
import re
import pty
import select
import signal
import subprocess
import termios
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('PHP_BINARY', 'php')


def start(live=False, code=None, dimensions=None, environment=None):
    master, slave = pty.openpty()
    if dimensions:
        fcntl.ioctl(slave, termios.TIOCSWINSZ, struct.pack('HHHH', *dimensions, 0, 0))
    mode = termios.tcgetattr(slave)
    settings = {**os.environ, 'TERM': 'xterm-256color', **(environment or {})}
    settings = {key: value for key, value in settings.items() if value is not None}
    command = ([PHP, '-r', code, str(ROOT / 'vendor/autoload.php')] if code else
               [PHP, str(ROOT / 'examples/console.php'), 'demo', 'console'] + (['--live'] if live else []))
    process = subprocess.Popen(
        command,
        stdin=slave, stdout=slave, stderr=slave,
        env=settings,
    )
    return process, master, slave, mode, bytearray()


def until(session, marker, timeout=5):
    process, master, _, _, output = session
    deadline = time.monotonic() + timeout
    while marker not in output:
        if time.monotonic() >= deadline:
            raise AssertionError(f'timed out waiting for {marker!r}: {bytes(output)!r}')
        if select.select([master], [], [], .05)[0]:
            try:
                chunk = os.read(master, 65536)
            except OSError:
                break
            output.extend(chunk)
        elif process.poll() is not None:
            break
    assert marker in output, bytes(output)


def finish(session, code=0):
    process, master, slave, mode, output = session
    try:
        assert process.wait(timeout=5) == code, bytes(output)
        while select.select([master], [], [], .05)[0]:
            try:
                chunk = os.read(master, 65536)
            except OSError:
                break
            if not chunk:
                break
            output.extend(chunk)
        restored = termios.tcgetattr(slave)
        # macOS 恢复规范输入模式时会设置瞬态的 PENDIN 标志。
        pending = getattr(termios, 'PENDIN', 0)
        restored[3] &= ~pending
        mode[3] &= ~pending
        assert restored == mode, f'terminal mode was not restored: {mode!r} -> {restored!r}'
        assert b'\x1b[?25h' in output, 'cursor was not restored'
        assert b'\x1b[?1049l' in output, 'screen was not restored'
        return bytes(output)
    finally:
        if process.poll() is None:
            process.kill()
            process.wait()
        os.close(master)
        os.close(slave)


# 完成多次真实刷新后，通过键盘退出。
session = start(True)
until(session, b'Refreshing')
time.sleep(.65)
os.write(session[1], b'q')
output = finish(session)
assert output.count(b'Refreshing') >= 2, output
print('PASS live refresh and q cleanup')

# 验证方向键导航、多选，以及返回规范模式后的行输入。
session = start()
until(session, b'Choose environments')
for key in [b' ', b'\x1b[B', b' ', b'\r']:
    os.write(session[1], key)
    time.sleep(.08)
until(session, b'Continue?')
os.write(session[1], b'y\n')
output = finish(session)
assert b'Selected: dev, test' in output, output
print('PASS arrow multiselect, confirmation, and business values')

session = start()
until(session, b'Choose environments')
os.write(session[1], b'q')
assert b'Cancelled' in finish(session)
print('PASS selection cancellation and cleanup')

session = start(True)
until(session, b'Refreshing')
os.write(session[1], b'\x03')
finish(session)
print('PASS Ctrl+C keyboard cleanup')

if subprocess.check_output([PHP, '-r', 'echo function_exists("pcntl_signal") ? "yes" : "no";']) == b'yes':
    session = start(True)
    until(session, b'Refreshing')
    os.kill(session[0].pid, signal.SIGTERM)
    finish(session, 128 + signal.SIGTERM)
    print('PASS external SIGTERM cleanup and exit code')
else:
    print('SKIP external SIGTERM cleanup (pcntl is unavailable)')

session = start(code=r"""
require $argv[1];
$terminal = new EasySwoole\Command\Console\Terminal();
try {
    $terminal->open();
    $terminal->frame('unicode probe');
    $key = $terminal->key();
} finally { $terminal->close(); }
echo 'unicode:' . $key;
""")
until(session, b'unicode probe')
os.write(session[1], '测'.encode())
assert 'unicode:测'.encode() in finish(session)
print('PASS UTF-8 keyboard decoding and cleanup')


# 在真实键盘流中搜索时插入 Delete，未知字符不能残留在搜索文本中。
session = start()
until(session, b'Choose environments')
for key in [b'/', b'Dev', b'\x1b[3~', b'elop', b'\r', b'\r']:
    os.write(session[1], key)
    time.sleep(.06)
until(session, b'Continue?')
os.write(session[1], b'y\n')
assert b'Selected: dev' in finish(session)
print('PASS complete Delete sequence in selection search')

# 五行终端仍展示当前项和取消入口，翻页及首尾键操作原始业务索引。
session = start(code=r"""
require $argv[1];
$console = new EasySwoole\Command\Console\Console();
$choices = [];
for ($i = 0; $i < 30; ++$i) {
    $choices[] = new EasySwoole\Command\Console\Selection\Choice($i, 'Item ' . $i, $i);
}
$result = $console->select('Small menu', $choices);
echo 'selected:' . implode(',', $result->values());
""", dimensions=(5, 30))
until(session, b'q Cancel')
os.write(session[1], b'\x1b[6~')
until(session, b'> [ ] Item 2')
for key in [b'\x1b[H', b'\x1b[F', b'\x1b[A', b'\r']:
    os.write(session[1], key)
    time.sleep(.06)
assert b'selected:29' in finish(session)
print('PASS compact viewport and Home End PageDown navigation')

# 动态表格保留颜色、正确截断中文；禁色环境只移除样式，不影响动态绘制。
for disabled in [False, True]:
    session = start(code=r"""
require $argv[1];
$console = new EasySwoole\Command\Console\Console();
$console->live(static fn () => new EasySwoole\Command\Console\View\TableView(['Status'], [
    [EasySwoole\Command\Console\Color::green('运行中')],
]), .1);
""", environment={'NO_COLOR': '1' if disabled else None})
    until(session, '运行中'.encode())
    os.write(session[1], b'q')
    output = finish(session)
    assert b'?[0;32m' not in output, output
    if disabled:
        assert re.search(rb'\x1b\[[0-9;:]*m', output) is None, output
    else:
        assert b'\x1b[0;32m' in output, output
    print('PASS live styled table with NO_COLOR' if disabled else 'PASS live styled table colors')
