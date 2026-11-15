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


def start(live=False, code=None, dimensions=None, environment=None,
          redirect_stdout=False, redirect_stdin=False, redirect_stderr=False):
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
        stdin=subprocess.DEVNULL if redirect_stdin else slave,
        stdout=subprocess.PIPE if redirect_stdout else slave,
        stderr=subprocess.PIPE if redirect_stderr else slave,
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


def finish(session, code=0, raw=True):
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
        if raw:
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


# 模拟第一次 stty 恢复失败，验证显式重试和进程退出时的单次重试都恢复真实终端。
for explicit in [True, False]:
    code = r"""
require $argv[1];
$terminal = new class extends EasySwoole\Command\Console\Terminal {
    private bool $closing = false;
    private bool $failOnce = true;
    public function close(): void {
        $this->closing = true;
        try { parent::close(); }
        finally { $this->closing = false; }
    }
    protected function command(array $arguments): ?string {
        if ($this->closing && $this->failOnce) {
            $this->failOnce = false;
            return null;
        }
        return parent::command($arguments);
    }
};
$console = new EasySwoole\Command\Console\Console(terminal: $terminal);
try { $console->live(static fn () => 'restore probe', .1); }
catch (EasySwoole\Command\Exception\TerminalException $error) { echo 'restore deferred'; }
if (EXPLICIT_RETRY) { $terminal->close(); }
""".replace('EXPLICIT_RETRY', 'true' if explicit else 'false')
    session = start(code=code)
    until(session, b'restore probe')
    os.write(session[1], b'q')
    assert b'restore deferred' in finish(session)
    print('PASS explicit terminal restore retry' if explicit else 'PASS shutdown terminal restore retry')

# 跨行样式被逐行裁剪时，下一行仍继承原始颜色。
session = start(code=r"""
require $argv[1];
$console = new EasySwoole\Command\Console\Console();
$console->live(static fn () => EasySwoole\Command\Console\Color::green("one\ntwo"), .1);
""", environment={'NO_COLOR': None})
until(session, b'\x1b[0;32mtwo')
os.write(session[1], b'q')
output = finish(session)
assert b'\x1b[0;32mone\x1b[0m' in output, output
print('PASS multiline live color continuation')

# 输入输出都是真实 TTY 时，显式禁用策略仍不能进入交互或切换终端模式。
session = start(code=r"""
require $argv[1];
$console = new EasySwoole\Command\Console\Console(allowInteraction: false);
try { $console->prompt('must not prompt'); }
catch (RuntimeException $error) { echo 'interaction blocked'; }
""")
until(session, b'interaction blocked')
output = finish(session, raw=False)
assert b'must not prompt' not in output, output
assert b'\x1b[?1049h' not in output, output
print('PASS explicit no interaction policy on a real TTY')


# stdout 被重定向时，编号菜单、提问和重试消息都应显示在终端。
session = start(code=r"""
require $argv[1];
$console = new EasySwoole\Command\Console\Console();
$choice = $console->select('Choose a target', [
    new EasySwoole\Command\Console\Selection\Choice('a', 'Primary', 'primary'),
    new EasySwoole\Command\Console\Selection\Choice('b', 'Backup', 'backup'),
], multiple: true);
if ($console->confirm('Confirm selection?')) {
    $console->line('Selected: ' . implode(',', $choice->values()));
}
$console->table(['Target', 'Status'], [[str_repeat('x', 100) . '/final.log', 'ready']]);
""", redirect_stdout=True)
until(session, b'Enter indexes')
os.write(session[1], b'wrong\n')
until(session, b'Invalid selection')
os.write(session[1], b'2,1,2\n')
until(session, b'Confirm selection?')
os.write(session[1], b'maybe\n')
until(session, b'Please enter y or n.')
os.write(session[1], b'yes\n')
output = finish(session, raw=False)
assert b'Choose a target' in output and b'[2] Backup' in output, output
assert b'Selected:' not in output, output
with session[0].stdout as stdout:
    result = stdout.read()
assert result.startswith(b'Selected: primary,backup\n'), result
assert b'x' * 100 + b'/final.log' in result, result
assert b'Choose a target' not in result and b'Confirm selection?' not in result, result
print('PASS redirected stdout keeps interaction on stderr and preserves full table cells')

# stdin 非终端时，也应通过输出终端获取表格宽度。
session = start(code=r"""
require $argv[1];
$console = new EasySwoole\Command\Console\Console();
$console->table(['Target', 'Status'], [[str_repeat('x', 100) . '/final.log', 'ready']]);
""", dimensions=(20, 120), redirect_stdin=True)
output = finish(session, raw=False)
assert b'x' * 100 + b'/final.log  ready' in output, output
print('PASS table honors output terminal width with redirected stdin')


# 输入仍是终端、两个输出流都被重定向时，交互应立即失败。
session = start(code=r"""
require $argv[1];
$console = new EasySwoole\Command\Console\Console();
foreach ([
    fn () => $console->prompt('Hidden prompt'),
    fn () => $console->confirm('Hidden confirmation'),
    fn () => $console->select('Hidden menu', [
        new EasySwoole\Command\Console\Selection\Choice('a', 'Primary', 'primary'),
    ]),
] as $interaction) {
    try {
        $interaction();
        exit(1);
    } catch (RuntimeException $error) {
        $console->error($error->getMessage());
    }
}
""", redirect_stdout=True, redirect_stderr=True)
finish(session, raw=False)
with session[0].stdout as stdout, session[0].stderr as stderr:
    assert stdout.read() == b''
    assert stderr.read().count(b'pass explicit parameters') == 3
print('PASS interactive calls reject hidden prompts when both output streams are redirected')
