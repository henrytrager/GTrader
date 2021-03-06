<?php

namespace GTrader\Strategies;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

use GTrader\Exchange;
use GTrader\Series;
use GTrader\Training;
use GTrader\Strategy;
use GTrader\Util;
use GTrader\Log;
use GTrader\TrainingManager;
use GTrader\Strategies\Fann as FannStrategy;

class FannTraining extends Training
{

    protected $strategies = [];
    protected $saved_fann;
    protected $reverts = 0;
    protected $started;


    public function run()
    {
        $this->init()
            ->obtainLock()
            ->setProgress('state', 'training')
            ->saveProgress();

        /*
        // Ignore the first 100 epochs
        if (!$this->getProgress('epoch') && $this->shouldRun()) {
            $this->getStrategy('train')->train(100);
            //$this->setProgress('epoch', $this->getProgress('epoch') + 100);
        }
        */

        while ($this->shouldRun()) {
            $this->swapIfCrossTraining()
                ->resetIfNoImprovement()
                ->increaseEpoch()
                ->setProgress(
                    'train_mser',
                    number_format(
                        $this->getStrategy('train')->getMSER(), 2, '.', '')
                    )
                ->saveHistory('train_mser', $this->getProgress('train_mser'))
                ->copyFann('train', 'test')
                ->pruneHistory();

            $test = $this->test('test');

            $this->setProgress('test', $test)
                ->saveHistory('test', $test)
                ->setProgress(
                    'no_improvement',
                    $this->getProgress('no_improvement') + 1
                );

            if ($this->acceptable('test', 10)) {
                $this->copyFann('train', 'verify');
                $verify = $this->test('verify');
                $this->setProgress('verify', $verify)
                    ->saveHistory('verify', $verify);
                if ($this->acceptable('verify', 80)) {
                    $this->brake(50);
                }
                if ($this->acceptable('verify')) {
                    $this->setProgress('test_max', $test)
                        ->setProgress('verify_max', $verify)
                        ->setProgress(
                            'last_improvement_epoch',
                            $this->getProgress('epoch')
                        )
                        ->setProgress('no_improvement', 0)
                        ->setProgress('epoch_jump', 1)
                        ->saveProgress()
                        ->saveFann();
                }
            }
            $this->setProgress(
                    'signals',
                    $this->getStrategy('test')->getNumSignals(true)
                )
                ->saveProgress()
                ->increaseJump()
                ->logMemoryUsage()
                ->train();
        }
        $this->setProgress('state', 'queued')
            ->saveProgress()
            ->saveFann($this->getParam('suffix'))
            ->releaseLock();

        return $this;
    }


    protected function init()
    {
        $exchange_name = Exchange::getNameById($this->exchange_id);
        $symbol_name = Exchange::getSymbolNameById($this->symbol_id);

        foreach ([
            'train_start', 'train_end',
            'test_start', 'test_end',
            'verify_start', 'verify_end'
        ] as $field) {
            if (!isset($this->options[$field])) {
                throw new \Exception('Missing option: '.$field);
            }
        }

        // Set up training strategy
        $train_candles = new Series([
            'exchange' => $exchange_name,
            'symbol' => $symbol_name,
            'resolution' => $this->resolution,
            'start' => $this->options['train_start'],
            'end' => $this->options['train_end'],
            'limit' => 0
        ]);

        define('FANN_WAKEUP_PREFERRED_SUFFX', $this->getParam('suffix'));
        $train_strategy = Strategy::load($this->strategy_id);
        $train_strategy->setCandles($train_candles);
        $train_candles->setStrategy($train_strategy);
        $this->setStrategy('train', $train_strategy);

        // Set up test strategy
        $test_candles = new Series([
            'exchange' => $exchange_name,
            'symbol' => $symbol_name,
            'resolution' => $this->resolution,
            'start' => $this->options['test_start'],
            'end' => $this->options['test_end'],
            'limit' => 0
        ]);
        $test_strategy = clone $train_strategy;
        $test_strategy->setCandles($test_candles);
        $test_candles->setStrategy($test_strategy);
        $this->setStrategy('test', $test_strategy);

        // Set up verify strategy
        $verify_candles = new Series([
            'exchange' => $exchange_name,
            'symbol' => $symbol_name,
            'resolution' => $this->resolution,
            'start' => $this->options['verify_start'],
            'end' => $this->options['verify_end'],
            'limit' => 0
        ]);
        $verify_strategy = clone $train_strategy;
        $verify_strategy->setCandles($verify_candles);
        $verify_candles->setStrategy($verify_strategy);
        $this->setStrategy('verify', $verify_strategy);

        $this->setProgress('epoch_jump', 1);
        if (!$this->getProgress('last_improvement_epoch')) {
            $this->setProgress('last_improvement_epoch', 0);
        }

        return $this;
    }


    protected function resetIfNoImprovement()
    {
        if (!$reset = ($this->options['reset_after'] ?? false)) {
            return $this;
        }

        $last = max(
            $this->getProgress('last_improvement_epoch'),
            $this->getProgress('last_reset')
        );
        if ($last < $this->getProgress('epoch') - $reset) {
            Log::sparse('Reset training');
            $this->setProgress('last_reset', $this->getProgress('epoch'))
                ->brake(100);
            $this->getStrategy('train')->createFann();
        }
        return $this;
    }


    protected function brake(int $percent)
    {
        $jump = floor($this->getProgress('epoch_jump') * (100 - $percent) / 100);
        if ($jump < 2) {
            $jump = 1;
        }
        $this->setProgress('epoch_jump', $jump);
        return $this;
    }


    protected function getStrategy(string $type)
    {
        return $this->strategies[$type] ?? null;
    }


    protected function setStrategy(string $type, FannStrategy $strategy)
    {
        $this->strategies[$type] = $strategy;
        return $this;
    }


    protected function train()
    {
        $this->getStrategy('train')->train($this->getProgress('epoch_jump'));
        return $this;
    }


    protected function copyFann(string $src, string $dest)
    {
        $this->getStrategy($dest)->setFann($this->getStrategy($src)->copyFann());
        return $this;
    }


    protected function saveFann(string $suffix = '')
    {
        $this->getStrategy('train')->saveFann($suffix);
        return $this;
    }


    protected function test(string $type)
    {
        $strat = $this->getStrategy($type);
        $candles = $strat->getCandles();
        $sig = $this->getMaximizeSig($strat);
        if (!($ind = $candles->getOrAddIndicator($sig))) {
            Log::error('Could not getOrAddIndicator() for '.$type);
            return 0;
        }
        $ind->addRef('root');
        return $ind->getLastValue(true);
    }


    protected function increaseEpoch()
    {
        $this->setProgress(
            'epoch',
            $this->getProgress('epoch') + $this->getProgress('epoch_jump')
        );
        return $this;
    }


    protected function swapIfCrossTraining()
    {
        $current_epoch = $this->getProgress('epoch');

        if (!isset($this->options['crosstrain'])) {
            return $this;
        }
        if (!$this->options['crosstrain'] || !$current_epoch) {
            return $this;
        }

        $last_epoch = max(
            $this->getProgress('last_improvement_epoch'),
            $this->getProgress('last_crosstrain_swap')
        );
        Log::sparse('Epoch: '.$current_epoch.' Last: '.$last_epoch);

        if (($this->getProgress('test') > 100 &&
            $this->acceptable('test', 70) &&
            $current_epoch >= $last_epoch + $this->options['crosstrain']) ||
            $current_epoch >= $last_epoch + $this->options['crosstrain'] * 10) {
            Log::sparse('*** Swap ***');
            $this->setProgress('last_crosstrain_swap', $current_epoch);

            Log::sparse('Before: '.$this->getProgress('test_before_swap').
                        ' Now: '.$this->getProgress('test'));
            if ($this->getProgress('test') < $this->getProgress('test_before_swap')) {
                if ($this->reverts < 3) {
                    Log::sparse('Reverting fann');
                    if (is_resource($this->saved_fann)) {
                        $this->getStrategy('train')->setFann($this->saved_fann);
                        $this->reverts++;
                    } else {
                        Log::error('Saved fann not resource');
                    }
                } else {
                    $this->reverts = 0;
                }
            }

            // Swap train and test ranges
            $train_candles = $this->getStrategy('train')->getCandles();
            $test_candles = $this->getStrategy('test')->getCandles();
            $this->getStrategy('train')->setCandles($test_candles);
            $this->getStrategy('test')->setCandles($train_candles);

            // Remove cached training data
            $this->getStrategy('train')->cleanCache();
            $this->getStrategy('test')->cleanCache();

            $test = $this->test('test');

            // Set test baseline
            $this->setProgress('test_max', $test);

            // Save test for reverting
            $this->setProgress('test_before_swap', $test);

            // Save fann for reverting
            $this->saved_fann = $this->getStrategy('train')->copyFann();
        }
        return $this;
    }


    protected function acceptable(string $type, int $allowed_regression_percent = 0)
    {
        $progress = $this->getProgress($type);
        return
            $progress > 0 &&
            $progress >=
            $this->getProgress($type.'_max') *
            (100 - $allowed_regression_percent) / 100;
    }


    protected function saveHistory($name, $value)
    {
        Log::sparse('saveHistory('.$this->getProgress('epoch').', '.$name.', '.$value.')');
        $this->getStrategy('train')
            ->saveHistory(
                $this->getProgress('epoch'),
                $name,
                $value
            );
        return $this;
    }


    protected function pruneHistory(int $limit = 15000, int $epochs = 1000, int $nth = 2)
    {
        $current_epoch = $this->getProgress('epoch');
        if ($current_epoch <= $this->getProgress('last_history_prune') + $epochs) {
            return $this;
        }
        if ($this->getStrategy('train')->getHistoryNumRecords() > $limit) {
            Log::sparse('Pruning history');
            $state = $this->getProgress('state');
            $this->setProgress('last_history_prune', $current_epoch)
                ->setProgress('state', 'pruning history')
                ->saveProgress();
            $this->getStrategy('train')->pruneHistory($nth);
            $this->setProgress('state', $state)
                ->saveProgress();
        }
        return $this;
    }


    protected function increaseJump()
    {
        if ($this->getProgress('no_improvement') > $this->getParam('max_boredom')) {
            // Increase jump size to fly over valleys faster, possibly missing some narrow peaks
            $this->setProgress('epoch_jump', $this->getProgress('epoch_jump') + 1);

            // Limit jumps
            if ($this->getProgress('epoch_jump') >= $this->getParam('epoch_jump_max')) {
                $this->setProgress('epoch_jump', $this->getParam('epoch_jump_max'));
            }
            $this->setProgress('no_improvement', 0);
        }
        return $this;
    }


    protected function shouldRun()
    {
        if (!$this->started) {
            $this->started = time();
            Log::sparse('Training start: '.date('Y-m-d H:i:s'));
        }

        // check db if we have been stopped or deleted
        try {
            self::where('id', $this->id)
                ->where('status', 'training')
                ->firstOrFail();
        } catch (\Exception $e) {
            Log::sparse('Training stopped.');
            return false;
        }
        // check if the number of active trainings is greater than the number of slots
        if (self::where('status', 'training')->count() > TrainingManager::getSlotCount()) {
            // check if we have spent too much time
            if ((time() - $this->started) > $this->getParam('max_time_per_session')) {
                Log::sparse('Time up: '.(time() - $this->started).'/'.$this->getParam('max_time_per_session'));
                return false;
            }
        }
        return true;
    }


    protected function setProgress($key, $value)
    {
        //Log::sparse('setProgress('.$key.', '.$value.')');
        $progress = $this->progress;
        if (!is_array($progress)) {
            $progress = [];
        }
        $this->progress = array_replace_recursive($progress, [$key => $value]);
        return $this;
    }


    protected function getProgress($key)
    {
        if (!is_array($this->progress)) {
            return 0;
        }
        return $this->progress[$key] ?? 0;
    }


    protected function saveProgress()
    {
        DB::table($this->table)
            ->where('id', $this->id)
            ->update(['progress' => json_encode($this->progress)]);
        return $this;
    }


    protected function logMemoryUsage()
    {
        Log::sparse('Memory used: '.Util::getMemoryUsage());
        return $this;
    }


    public function getPreferences()
    {
        $prefs = [];
        foreach (['crosstrain', 'reset_after'] as $item) {
            $prefs[$item] = $this->getParam($item);
        }
        return array_replace_recursive(
            parent::getPreferences(),
            $prefs
        );
    }


    public function handleStartRequest(Request $request)
    {
        if (!$strategy = $this->loadStrategy()) {
            Log::error('Could not load strategy');
            return response('Strategy not found', 403);
        }

        $options = $this->options ?? [];

        foreach (['crosstrain', 'reset_after'] as $item) {
            $prefs[$item] = $options[$item] = $request->$item ?? 0;
        }

        if ($options['crosstrain'] < 2) {
            $options['crosstrain'] = 0;
        }
        if ($options['crosstrain'] > 10000) {
            $options['crosstrain'] = 10000;
        }

        $options['reset_after'] = $request->reset_after ?? 0;
        if ($options['reset_after'] < 100) {
            $options['reset_after'] = 0;
        }
        if ($options['reset_after'] > 10000) {
            $options['reset_after'] = 10000;
        }

        $this->options = $options;

        Auth::user()->setPreference(
            $this->getShortClass(),
            $prefs
        )->save();

        $from_scratch = true;
        if ($strategy->hasBeenTrained()) {
            $from_scratch = intval($request->from_scratch ?? 0);
        }
        if ($from_scratch) {
            Log::info('Training from scratch.');
            $strategy->fromScratch();
        } else {
            $last_epoch = $strategy->getLastTrainingEpoch();
            Log::info('Continuing training from epoch '.$last_epoch);
            $this->progress = ['epoch' => $last_epoch];
        }

        $strategy->setParam(
            'last_training',
            array_merge($strategy->getParam('last_training', []), $options)
        )->save();

        return parent::handleStartRequest($request);
    }
}
