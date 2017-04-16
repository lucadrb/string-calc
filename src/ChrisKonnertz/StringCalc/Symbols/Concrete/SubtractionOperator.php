<?php

namespace ChrisKonnertz\StringCalc\Symbols\Concrete;

use ChrisKonnertz\StringCalc\Symbols\AbstractOperator;

/**
 * Operator for mathematical multiplication.
 * Example: "1+2" => 3
 * @see https://en.wikipedia.org/wiki/Multiplication
 *
 * @package ChrisKonnertz\StringCalc\Symbols\Concrete
 */
class SubtractionOperator extends AbstractOperator
{

    /**
     * @inheritdoc
     */
    protected $identifiers = ['-'];

    /**
     * @inheritdoc
     */
    const PRECEDENCE = 100;

    /**
     * @inheritdoc
     * The subtraction operator is unary AND binary!
     */
    const UNARY = true;

    /**
     * @inheritdoc
     */
    public function operate($leftNumber, $rightNumber)
    {
        return $leftNumber - $rightNumber;
    }

}