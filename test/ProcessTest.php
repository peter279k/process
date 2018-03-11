<?php

namespace Amp\Process\Test;

use Amp\ByteStream\Message;
use Amp\Delayed;
use Amp\Loop;
use Amp\Process\Process;
use Amp\Process\ProcessInputStream;
use Amp\Process\ProcessOutputStream;
use Amp\Promise;
use PHPUnit\Framework\TestCase;

class ProcessTest extends TestCase {
    const CMD_PROCESS = \DIRECTORY_SEPARATOR === "\\" ? "cmd /c echo foo" : "echo foo";
    const CMD_PROCESS_SLOW = \DIRECTORY_SEPARATOR === "\\" ? "cmd /c ping -n 3 127.0.0.1 > nul" : "sleep 2";

    /**
     * @expectedException \Amp\Process\StatusError
     */
    public function testMultipleExecution() {
        Loop::run(function () {
            $process = new Process(self::CMD_PROCESS);
            $process->start();
            $process->start();
        });
    }

    public function testIsRunning() {
        Loop::run(function () {
            $process = new Process(\DIRECTORY_SEPARATOR === "\\" ? "cmd /c exit 42" : "exit 42");
            $process->start();
            $promise = $process->join();

            $this->assertTrue($process->isRunning());

            yield $promise;

            $this->assertFalse($process->isRunning());
        });
    }

    public function testExecuteResolvesToExitCode() {
        Loop::run(function () {
            $process = new Process(\DIRECTORY_SEPARATOR === "\\" ? "cmd /c exit 42" : "exit 42");
            $process->start();

            $code = yield $process->join();

            $this->assertSame(42, $code);
            $this->assertFalse($process->isRunning());
        });
    }

    public function testCommandCanRun() {
        Loop::run(function () {
            $process = new Process(self::CMD_PROCESS);
            $process->start();

            $this->assertInternalType('int', yield $process->getPid());
            $this->assertSame(0, yield $process->join());
        });
    }

    public function testProcessCanTerminate() {
        if (\DIRECTORY_SEPARATOR === "\\") {
            $this->markTestSkipped("Signals are not supported on Windows");
        }

        Loop::run(function () {
            $process = new Process(self::CMD_PROCESS_SLOW);
            $process->start();
            $process->signal(0);
            $this->assertInstanceOf(Promise::class, $process->getPid());
            $this->assertSame(0, yield $process->join());
        });
    }

    public function testGetWorkingDirectoryIsDefault() {
        Loop::run(function () {
            $process = new Process(self::CMD_PROCESS);
            $this->assertSame(getcwd(), $process->getWorkingDirectory());
        });
    }

    public function testGetWorkingDirectoryIsCustomized() {
        Loop::run(function () {
            $process = new Process(self::CMD_PROCESS, __DIR__);
            $this->assertSame(__DIR__, $process->getWorkingDirectory());
        });
    }

    public function testGetEnv() {
        Loop::run(function () {
            $process = new Process(self::CMD_PROCESS);
            $this->assertSame([], $process->getEnv());
        });
    }

    public function testGetStdin() {
        Loop::run(function () {
            $process = new Process(self::CMD_PROCESS);
            $process->start();
            $this->assertInstanceOf(ProcessOutputStream::class, $process->getStdin());
            yield $process->join();
        });
    }

    public function testGetStdout() {
        Loop::run(function () {
            $process = new Process(self::CMD_PROCESS);
            $process->start();
            $this->assertInstanceOf(ProcessInputStream::class, $process->getStdout());
            yield $process->join();
        });
    }

    public function testGetStderr() {
        Loop::run(function () {
            $process = new Process(self::CMD_PROCESS);
            $process->start();
            $this->assertInstanceOf(ProcessInputStream::class, $process->getStderr());
            yield $process->join();
        });
    }

    public function testProcessEnvIsValid() {
        Loop::run(function () {
            $process = new Process(self::CMD_PROCESS, null, [
                'test' => 'foobar',
                'PATH' => \getenv('PATH'),
                'SystemRoot' => \getenv('SystemRoot') ?: '', // required on Windows for process wrapper
            ]);
            $process->start();
            $this->assertSame('foobar', $process->getEnv()['test']);
            yield $process->join();
        });
    }

    /**
     * @expectedException \Error
     */
    public function testProcessEnvIsInvalid() {
        $process = new Process(self::CMD_PROCESS, null, [
            ['error_value']
        ]);
    }

    /**
     * @expectedException \Amp\Process\StatusError
     * @expectedExceptionMessage Process has not been started.
     */
    public function testGetStdinIsStatusError() {
        $process = new Process(self::CMD_PROCESS, null, []);
        $process->getStdin();
    }

    /**
     * @expectedException \Amp\Process\StatusError
     * @expectedExceptionMessage Process has not been started.
     */
    public function testGetStdoutIsStatusError() {
        $process = new Process(self::CMD_PROCESS, null, []);
        $process->getStdout();
    }

    /**
     * @expectedException \Amp\Process\StatusError
     * @expectedExceptionMessage Process has not been started.
     */
    public function testGetStderrIsStatusError() {
        $process = new Process(self::CMD_PROCESS, null, []);
        $process->getStderr();
    }

    /**
     * @expectedException \Error
     * @expectedExceptionMessage Cloning is not allowed!
     */
    public function testProcessCantBeCloned() {
        $process = new Process(self::CMD_PROCESS);
        clone $process;
    }

    /**
     * @expectedException \Amp\Process\ProcessException
     * @expectedExceptionMessage The process was killed
     */
    public function testKillImmediately() {
        Loop::run(function () {
            $process = new Process(self::CMD_PROCESS_SLOW);
            $process->start();
            $process->kill();
            yield $process->join();
        });
    }

    /**
     * @expectedException \Amp\Process\ProcessException
     * @expectedExceptionMessage The process was killed
     */
    public function testKillThenReadStdout() {
        Loop::run(function () {
            $process = new Process(self::CMD_PROCESS_SLOW);
            $process->start();

            yield new Delayed(100); // Give process a chance to start, otherwise a different error is thrown.

            $process->kill();

            $this->assertNull(yield $process->getStdout()->read());

            yield $process->join();
        });
    }


    /**
     * @expectedException \Amp\Process\StatusError
     * @expectedExceptionMessage Process has not been started.
     */
    public function testProcessHasNotBeenStartedWithJoin() {
        Loop::run(function () {
            $process = new Process(self::CMD_PROCESS);
            yield $process->join();
        });
    }

    /**
     * @expectedException \Amp\Process\StatusError
     * @expectedExceptionMessage Process has not been started.
     */
    public function testProcessHasNotBeenStartedWithGetPid() {
        Loop::run(function () {
            $process = new Process(self::CMD_PROCESS);
            yield $process->getPid();
        });
    }

    /**
     * @expectedException \Amp\Process\StatusError
     * @expectedExceptionMessage Process is not running.
     */
    public function testProcessIsNotRunningWithKill() {
        Loop::run(function () {
            $process = new Process(self::CMD_PROCESS);
            $process->kill();
        });
    }

    /**
     * @expectedException \Amp\Process\StatusError
     * @expectedExceptionMessage Process is not running.
     */
    public function testProcessIsNotRunningWithSignal() {
        Loop::run(function () {
            $process = new Process(self::CMD_PROCESS);
            $process->signal(0);
        });
    }

    /**
     * @expectedException \Amp\Process\StatusError
     * @expectedExceptionMessage Process has not been started.
     */
    public function testProcessHasBeenStarted() {
        Loop::run(function () {
            $process = new Process(self::CMD_PROCESS);
            yield $process->join();
        });
    }

    /**
     * @expectedException \Amp\Process\StatusError
     * @expectedExceptionMessage Process has not been started.
     */
    public function testProcessHasNotBeenStartedWithGetPid() {
        Loop::run(function () {
            $process = new Process(self::CMD_PROCESS);
            $promise = $process->getPid();

            yield $promise;
        });
    }

    /**
     * @expectedException \Amp\Process\StatusError
     * @expectedExceptionMessage Process is not running.
     */
    public function testProcessIsNotRunningWithKill() {
        Loop::run(function () {
            $process = new Process(self::CMD_PROCESS);

            $process->kill();

            yield $promise;
        });
    }

    /**
     * @expectedException \Amp\Process\StatusError
     * @expectedExceptionMessage Process is not running.
     */
    public function testProcessIsNotRunningWithSignal() {
        Loop::run(function () {
            $process = new Process(self::CMD_PROCESS);

            $process->signal(0);

            yield $promise;
        });
    }

    /**
     * @expectedException \Amp\Process\StatusError
     * @expectedExceptionMessage Process has not been started.
     */
    public function testProcessHasBeenStarted() {
        Loop::run(function () {
            $process = new Process(self::CMD_PROCESS);
            $promise = $process->join();

            yield $promise;
        });
    }

    public function testCommand() {
        $process = new Process([self::CMD_PROCESS]);
        $this->assertSame(\implode(" ", \array_map("escapeshellarg", [self::CMD_PROCESS])), $process->getCommand());
    }

    public function testOptions() {
        $process = new Process(self::CMD_PROCESS);
        $this->assertSame([], $process->getOptions());
    }

    public function getProcessCounts(): array {
        return \array_map(function (int $count): array {
            return [$count];
        }, \range(2, 32, 2));
    }

    /**
     * @dataProvider getProcessCounts
     *
     * @param int $count
     */
    public function testSpawnMultipleProcesses(int $count) {
        Loop::run(function () use ($count) {
            $processes = [];
            for ($i = 0; $i < $count; ++$i) {
                $command = \DIRECTORY_SEPARATOR === "\\" ? "cmd /c exit $i" : "exit $i";
                $processes[] = new Process(self::CMD_PROCESS_SLOW . " && " . $command);
            }

            $promises = [];
            foreach ($processes as $process) {
                $process->start();
                $promises[] = $process->join();
            }

            $this->assertSame(\range(0, $count - 1), yield $promises);
        });
    }

    public function testReadOutputAfterExit() {
        Loop::run(function () {
            $process = new Process(["php", __DIR__ . "/bin/worker.php"]);
            $process->start();

            $process->getStdin()->write("exit 2");
            $this->assertSame("..", yield $process->getStdout()->read());

            $this->assertSame(0, yield $process->join());
        });
    }

    public function testReadOutputAfterExitWithLongOutput() {
        Loop::run(function () {
            $process = new Process(["php", __DIR__ . "/bin/worker.php"]);
            $process->start();

            $count = 128 * 1024 + 1;
            $process->getStdin()->write("exit " . $count);
            $this->assertSame(str_repeat(".", $count), yield new Message($process->getStdout()));

            $this->assertSame(0, yield $process->join());
        });
    }
}
