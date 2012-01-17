<?php
/**
 * Process Management Class
 *
 * @package 		opensourceame
 * @subpackage		process
 * @author			David Kelly
 * @copyright		David Kelly, 2011 (http://opensourceame.com)
 * @see				http://opensourceame.com
 */

namespace opensourceame;

/**
 * Process class for controlling an external process
 *
 * @author 			David Kelly
 * @version			2.2
 */
class process
{
	const		version			= '2.2.1';

	public		$pipeSpec		= array(
				   0 => array("pipe", "r"),
				   1 => array("pipe", "w"),
				   2 => array("pipe", "w"),
				);

	public		$pipes				= array();
	public		$proc				= null;
	public		$command			= null;
	public		$startTime			= null;
	public		$startImmediately	= false;
	public		$startDirectory		= null;
	public		$startEnv			= null;
	public		$startOptions		= null;
	public		$stopTime			= null;
	public		$timeout			= 60;
	public		$stdout				= null;
	public		$stderr				= null;
	public		$status				= array();
	public		$started			= false;
	public 		$stopped			= true;
	public		$exitCode			= -2;
	public		$readOutput			= true;
	public		$readOutputFinal	= true;
	public		$readBuffer			= 65535;
	public		$writeBuffer		= 1024;
	public		$checkInterval		= 0.01;
	public		$trace				= false;
	public		$traceProg			= 'strace';
	public		$traceFile			= null;
	public		$outputFile			= null;

	protected	$niceness			= 0;
	protected	$stdoutHandle		= null;
	protected	$stderrHandle		= null;
	protected	$running			= false;
	protected	$tracing			= false;
	protected	$log				= array();
	protected	$_logger			= null;
	protected	$_manager			= false;
	protected	$_trace				= null;

	/**
	 * Object constructor, can be passed config as an array
	 *
	 * @param array $config
	 */
	public function __construct($config = false)
	{
		if(is_array($config))
		{
			$reflection = new \ReflectionClass($this);

			foreach($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $p)
			{
				if(isset($config[$p->name]))
				{
					$this->{$p->name} = $config[$p->name];
				}
			}

		}

		if($this->startImmediately)
		{
			$this->start();
		}

		return $this;
	}

	/**
	 * Link this process back to a process manager
	 *
	 * @param processManager $m
	 */
	public function setManager(processManager $m)
	{
		$this->_manager == $m;

		return true;
	}

	/**
	 * Log a message
	 *
	 * @param int $level logging level
	 * @param string $message log message
	 */
	public function log($level, $message)
	{
		$this->logs[] = $message;
		return false;

		$this->_logger->log($level, $message);
	}

	private function tracer()
	{
		return $this->_trace;
	}

	/**
	 * Run the process
	 *
	 * This is similar to running shell_exec() but has the advantages of being able to specify
	 */
	public	function run()
	{
		$this->start();

		while($this->running())
		{
			sleep($this->checkInterval);
		}

		return true;
	}

	/**
	 * Start a process
	 *
	 * @return		boolean
	 */
	public	function start()
	{
		// skip start if already started
		if($this->startTime != null)
			return true;

		// increase the PHP max_execution_time if the timeout is greater
		if ($this->_manager === false and ($this->timeout > ini_get('max_execution_time') )
		or ($this->_manager !== false and ($this->timeout > $this->_manager->timeout) )
		)
		{
			set_time_limit((int) $this->timeout);
		}

		$this->startTime 	= microtime(true);
		$this->proc 		= proc_open($this->command, $this->pipeSpec, $this->pipes, $this->startDirectory, $this->startEnv, $this->startOptions);

		if($this->proc === false)
		{
			$this->log(0, "failed to start process");

			return false;
		}

		$this->started		= true;
		$this->stopped		= false;
		$this->running		= true;

		if($this->trace !== false)
		{
			$this->startTrace();
		}


		stream_set_blocking($this->pipes[1], false);
		stream_set_blocking($this->pipes[2], false);

		if (version_compare(PHP_VERSION, '5.3.3') >= 0)
		{
			stream_set_read_buffer($this->pipes[1], $this->readBuffer);
		}
		
		$this->stdoutHandle		= &$this->pipes[1];
		$this->stderrHandle		= &$this->pipes[2];


		$this->check();

		return true;
	}

	/**
	 * Start a trace on the process
	 *
	 * @param string $traceArgs
	 */
	public function startTrace(\string $traceArgs = null)
	{
		$this->getStatus();

		$pid = $this->pid();

		if($this->traceFile == null)
		{
			$this->traceFile = sys_get_temp_dir()."/process_$pid.trace";
		}

		// start up a trace process
		$this->_trace = new process( array(
			'command'		=> "$this->traceProg -p $pid -v -f $traceArgs -o $this->traceFile",
			'timeout'		=> $this->timeout + 5,
		));

		$this->_trace->start();

		return true;
	}

	/**
	 * Return the trace data
	 */
	public function traceData()
	{
		return file_get_contents($this->traceFile);
	}

	/**
	 * Stop the process
	 *
	 * @return			boolean
	 */
	public function stop($signal = null)
	{
		if($signal == null)
			$signal = 15;

		$this->readOutput();

		if($this->trace)
		{
			$this->_trace->stop();
		}


		$result = proc_terminate($this->proc, $signal);

		if($this->outputFile)
		{
			file_write_contents($this->outputFile, $this->stdout);
		}

		$this->stopped = true;

		return true;
	}

	/**
	 * kill() is an alias for stop with signal 9
	 */
	public function kill()
	{
		return $this->stop(9);
	}

	/**
	 * Check the process
	 *
	 * @return true
	 */
	public function check()
	{
		$this->getStatus();

		$this->readOutput();

		return true;
	}

	/**
	 * Check if the process has started
	 */
	public	function started()
	{
		return $this->started;
	}

	/**
	 * Read output from the streams
	 */
	public	function readOutput()
	{
		if(! $this->running and ! $this->readOutputFinal)
		{
				// read remaining buffered output
				while (! feof($this->pipes[1]))
				{
					$this->stdout .= stream_get_contents($this->pipes[1]);
					$this->stderr .= stream_get_contents($this->pipes[2]);
				}

				$this->readOutputFinal = true;
		}

		if($this->trace)
		{
			$this->tracer()->readOutput();
		}

		if(! $this->readOutput)
			return false;

		$this->stdout .= fgets($this->pipes[1], $this->readBuffer);
		$this->stderr .= fgets($this->pipes[2], $this->readBuffer);

		return true;
	}

	/**
	 * @return string Output from the process
	 */
	public function getOutput()
	{
		return $this->stdout;
	}
	
	/**
	 * @return string Errors from the process
	 */
	public function getErrors()
	{
		return $this->stderr;
	}
	
	/**
	 * Fetch process status
	 */
	protected	function getStatus()
	{
		if($this->running == false)
			return true;

		if(! is_resource($this->proc))
		{
			$this->log(-1, "proc went away");

			return false;
		}

		$this->status 	= proc_get_status($this->proc);

		$this->running 	= $this->status['running'];

		return true;
	}

	/**
	 * Return the exitcode for a terminated process, or false if still running
	 * @return 		integer
	 */
	public	function exitCode()
	{
		if($this->running())
		{
			return false;
		}

		if($this->exitCode == -2)
			$this->exitCode = $this->status['exitcode'];

		return $this->exitCode;
	}

	/**
	 * Get process status
	 *
	 * @return		string
	 */
	public	function status($type = null)
	{
		$this->check();

		if($type == null)
			return $this->status;

		return $this->status[$type];
	}

	/**
	 * Return the time spent executing this process
	 *
	 */
	public	function executionTime()
	{
		if($this->status['running'])
			$this->stopTime = microtime(true);

		$t = $this->stopTime - $this->startTime;

		if($t < 0)
			return 0;

		return round($t, 2);
	}

	public	function timeout()
	{
		$this->stop();

		$t = $this->executionTime();

		$this->status['running']	= false;
		$this->status['info'] 		= "timed out after $t seconds";
		$this->status['exitcode']	= 2;

		$this->log(1, "timed out after $t seconds");

		return true;
	}

	/**
	 * Check if a process is running.
	 * Time it out if it has run longer than configured time limit
	 */
	public	function running()
	{
		$this->check();

		if($this->running == false)
		{
			return false;
		}

		$this->stopTime = microtime(true);

		if( $this->executionTime() >= $this->timeout)
		{
			$this->timeout();

			return false;
		}

		return true;
	}

	/**
	 * Return process ID
	 *
	 * @return 		integer
	 */
	public function pid()
	{

		if(isset($this->status['pid']))
		{
			return $this->status['pid'];
		}

		return false;
	}

	/**
	 * Renice the process, equivalent to shell renice -p 123 10
	 *
	 * @param 		int 		$niceness		niceness level to renice this process to
	 * @return		boolean
	 */
	public function renice(\int $niceness)
	{
		$result = proc_nice($this->proc, $niceness - $proc->niceness);

		if($result)
		{
			$this->niceness = $niceness;

			return true;
		}

		$this->log(1, "unable to renice process to $niceness");

		return false;
	}

	/**
	 * Nice the process by given priority
	 *
	 * @param 	int 		$amount			amount to nice
	 * $return	boolean
	 */
	public function nice(\int $amount)
	{
		$result =  proc_nice($this->proc, $amount);

		if($result)
		{
			$this->niceness += $amount;

			return true;
		}

		$this->log(1, "unable to nice process by $amount");

		return false;

	}
}

/**
 * The processManager class can control multiple processes at the same time
 *
 * @author 		David Kelly
 * @version		1.3
 *
 */
class processManager
{
	const		version				= '1.4';
	const		modeParallel		= 1;
	const		modeQueue			= 2;

	public		$mode				= self::modeParallel;
	public		$checkInterval		= 0.01;

	protected	$processes			= array();
	protected	$timeout			= 30;
	protected	$started			= false;
	protected	$queuePoint			= 0;

	/**
	 * Object constructor, can be passed config as an array
	 *
	 * @param array $config
	 */
	public function __construct($config = false)
	{
		if(is_array($config))
		{
			$reflection = new \ReflectionClass($this);

			foreach($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $p)
			{
				if(isset($config[$p->name]))
				{
					$this->{$p->name} = $config[$p->name];
				}
			}

		}

		return $this;
	}

	public function add($config)
	{
		return $this->addProcess(new process($config));
	}

	public function addProcess(process $process)
	{
		$this->processes[]	= $process;

		$this->timeout = max($this->timeout, $process->timeout);

		return $process;
	}

	/**
	 * Return a process
	 *
	 * @param integer $n
	 */
	public function getProcess($n)
	{

		return $this->processes[$n];
	}

	public function sleep()
	{
		return sleep($this->checkInterval);
	}

	/**
	 * Are any processes still running?
	 *
	 * @return		boolean
	 */
	public function running()
	{
		foreach($this->processes as $proc)
		{
			if($proc->running())
			{
				return true;
			}
		}

		if($this->mode == self::modeQueue)
		{
			// start next in queue
			if($this->queuePoint < $this->processCount())
			{
				return $this->startNext();
			}

			return $this->getProcess($this->queuePoint)->running();
		}

		return false;

	}

	/**
	 * Run processes
	 */
	public function run()
	{
		if(! $this->started)
		{
			$this->start();
		}

		$run = true;

		while($this->running())
		{
			$this->sleep();
		}

		return true;
	}

	/**
	 * Start all processes
	 *
	 * @return		boolean
	 */
	public function start()
	{
		$started = true;

		if($this->mode == self::modeParallel)
		{
			foreach($this->processes as $proc)
			{
				if(! $proc->start())
				{
					$started = false;
				}
			}
		}

		if($this->mode == self::modeQueue)
		{
			// start just the first process
			$started = $this->startNext();
		}

		$this->started = $started;

		return $started;
	}

	/**
	 * Start the next process in the queue
	 */
	public function startNext()
	{
		// ignore calls to start a process number beyond the queue
		if($this->queuePoint > $this->processCount() )
		{
			return false;
		}

		// first check that the current process isn't still running
		if($this->getProcess($this->queuePoint)->running() )
		{
			return false;
		}

		$this->queuePoint ++;

		return $this->getProcesses($this->queuePoint)->start();
	}

	/**
	 * Return an array of processes
	 *
	 * @return		array		an array of process objects
	 */
	public function processes()
	{
		return $this->processes;
	}

	public function processCount()
	{
		return count($this->processes());
	}

	/**
	 * Return a single process
	 *
	 * @param		$n		string			process number
	 * @return		process
	 */
	public function process($n)
	{
		return $this->processes[$n];
	}
}
