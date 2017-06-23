<?php

namespace GTrader\Indicators;

use GTrader\Indicators\HasInputs;

class Pass extends HasInputs
{

    protected function getInputIndicator($signature)
    {
        return $this->getOwner()->getOrAddIndicator($signature);
    }

    protected function getInputNormParams(string $source = 'source')
    {
        $norm = parent::getNormalizeParams();
        $input = $this->getParam('indicator.input_'.$source, 'open');
        if (in_array($input, ['open', 'high', 'low', 'close'])) {
            return array_replace_recursive($norm, ['type' => 'ohlc']);
        }
        if (!$indicator = $this->getInputIndicator($input)) {
            return array_replace_recursive($norm, ['type' => 'individual']);
        }
        //error_log('Pass::getInputNormParams() return norm from ind: '.json_encode($indicator->getNormalizeParams()));
        return $indicator->getNormalizeParams();
    }


    public function getNormalizeParams()
    {
        $mode = $this->getParam('indicator.mode');
        $norm = parent::getNormalizeParams();
        if (in_array($mode, ['high', 'low'])) {
            return array_replace_recursive(
                $this->getInputNormParams($mode.'Ref'),
                $this->getInputNormParams('source')
            );
        }
        // Band
        $high = $this->getInputNormParams('highRef');
        $low = $this->getInputNormParams('lowRef');
        if ($high === $low) {
            return $high;
        }
        if ('range' === $high['type'] && 'range' === $low['type']) {
            return array_replace_recursive($norm, [
                'type' => 'range',
                'range' => [
                    'min' => min($high['range']['min'], $low['range']['min']),
                    'max' => max($high['range']['max'], $low['range']['max'])
                ],
            ]);
        }
        // TODO handle more possible scenarios
        return $norm;
    }

    public function getDisplaySignature(string $format = 'long')
    {
        $mode = $this->getParam('indicator.mode');
        $name = ucfirst($mode).' Pass';
        if ('short' === $format) {
            return $name;
        }
        $except = ['mode'];
        if ('high' === $mode) {
            $except[] = 'input_lowRef';
        }
        elseif ('low' === $mode) {
            $except[] = 'input_highRef';
        }
        return ($param_str = $this->getParamString($except)) ? $name.' ('.$param_str.')' : $name;
    }


    public function runDependencies(bool $force_rerun = false)
    {
        $mode = $this->getParam('indicator.mode');
        $sigs[] = $this->getInput();
        if (in_array($mode, ['high', 'band'])) {
            $sigs[] = $this->getParam('indicator.input_highRef');
        }
        if (in_array($mode, ['low', 'band'])) {
            $sigs[] = $this->getParam('indicator.input_lowRef');
        }

        $params = ['display' => ['visible' => false]];
        foreach ($sigs as $sig) {
            if ($indicator = $this->getOwner()->getOrAddIndicator($sig, [], $params)) {
                $indicator->checkAndRun();
            }
        }
        return $this;
    }


    protected function pass(string $mode, float $val, float $ref, bool $inclusive)
    {
        return ('high' === $mode) ?
            (
                $inclusive ?
                (($val >= $ref) ? $val : $ref) :
                (($val > $ref)  ? $val : $ref)
            ) :
            ( // low
                $inclusive ?
                (($val <= $ref) ? $val : $ref) :
                (($val < $ref)  ? $val : $ref)
            );
    }

    public function calculate(bool $force_rerun = false)
    {
        $this->runDependencies($force_rerun);

        if (!($candles = $this->getCandles())) {
            return $this;
        }

        $mode = $this->getParam('indicator.mode');
        $inc = boolval($this->getParam('indicator.inclusive'));
        $input = $this->getInput('input_source');
        $source = $candles->key($input);
        $high_ref = $candles->key($this->getInput('input_highRef'));
        $low_ref = $candles->key($this->getInput('input_lowRef'));

        $this->setParam(
            'display.y_axis_pos',
            in_array($input, ['open', 'high', 'low', 'close']) ? 'left' : 'right'
        );
        //dd($this->getParams());

        $signature = $candles->key($this->getSignature());

        $candles->reset();
        while ($candle = $candles->next()) {
            $val = floatval($candle->$source);
            if ('high' === $mode) {
                //error_log('IS: '.$input.' V: '.$val.' HR: '.$candle->$high_ref); //continue;
                $val = $this->pass($mode, $val, $candle->$high_ref, $inc);
            }
            else if ('low' === $mode) {
                //error_log('IS: '.$input.' V: '.$val.' LR: '.$candle->$low_ref); continue;
                $val = $this->pass($mode, $val, $candle->$low_ref, $inc);
            }
            else if ('band' === $mode) {
                $val = $this->pass(
                    'high',
                    $this->pass('low', $val, $candle->$high_ref, $inc),
                    $candle->$low_ref,
                    $inc
                );
            }
            $candle->$signature = $val;
        }
        //dd($candles[17]);
        //dd($this->getParams());
        return $this;
    }
}