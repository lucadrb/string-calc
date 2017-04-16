<?php

namespace ChrisKonnertz\StringCalc\Parser;

use ChrisKonnertz\StringCalc\Exceptions\NotFoundException;
use ChrisKonnertz\StringCalc\Exceptions\ParserException;
use ChrisKonnertz\StringCalc\Symbols\AbstractBracket;
use ChrisKonnertz\StringCalc\Symbols\AbstractClosingBracket;
use ChrisKonnertz\StringCalc\Symbols\AbstractFunction;
use ChrisKonnertz\StringCalc\Symbols\AbstractOpeningBracket;
use ChrisKonnertz\StringCalc\Symbols\AbstractOperator;
use ChrisKonnertz\StringCalc\Symbols\Concrete\Number;
use ChrisKonnertz\StringCalc\Symbols\SymbolContainerInterface;
use ChrisKonnertz\StringCalc\Tokenizer\Token;

/**
 * The parsers has one important method: parse()
 * It takes an array of tokens as input and
 * returns an array of nodes as output.
 * These nodes are the syntax tree of the term.
 *
 * @package ChrisKonnertz\StringCalc\Parser
 */
class Parser
{

    /**
     * The symbol container with all possible symbols
     *
     * @var SymbolContainerInterface
     */
    protected $symbolContainer;

    /**
     * Parser constructor.
     *
     * @param SymbolContainerInterface $symbolContainer
     */
    public function __construct(SymbolContainerInterface $symbolContainer)
    {
        $this->symbolContainer = $symbolContainer;
    }

    /**
     * Parses an array with tokens. Returns an array of nodes.
     * These nodes define a syntax tree.
     *
     * @param Token[] $tokens
     * @return ContainerNode
     */
    public function parse(array $tokens)
    {
        $symbolNodes = $this->detectSymbols($tokens);

        $nodes = $this->createTreeByBrackets($symbolNodes);

        $nodes = $this->transformTreeByFunctions($nodes);

        $nodes = $this->transformTreeByUnaryOperators($nodes);

        $this->checkGrammar($nodes);

        $nodes = $this->sortNodesByPrecedence($nodes);

        // Wrap the nodes in an array node. This will sort the nodes on level-0 according to their precedence.
        $rootNode = new ContainerNode($nodes);

        return $rootNode;
    }

    /**
     * Creates a flat array of symbol nodes from tokens.
     *
     * @param Token[] $tokens
     * @return SymbolNode[]
     * @throws \Exception
     */
    protected function detectSymbols(array $tokens)
    {
        $symbolNodes = [];

        $expectingOpeningBracket = false; // True if we expect an opening bracket (after a function name)
        $openBracketCounter = 0;

        foreach ($tokens as $token) {
            $type = $token->getType();

            if ($type == Token::TYPE_WORD) {
                $identifier = $token->getValue();
                $symbol = $this->symbolContainer->find($identifier);

                if ($symbol === null) {
                    throw new NotFoundException('Error: Detected unknown or invalid identifier.');
                }
            } elseif ($type == Token::TYPE_NUMBER) {
                // Notice: Numbers do not have an identifier
                $symbol = $this->symbolContainer->findSubtype(Number::class)[0];
            } else { // Type Token::TYPE_CHARACTER:
                $identifier = $token->getValue();
                $symbol = $this->symbolContainer->find($identifier);

                if ($symbol === null) {
                    throw new NotFoundException('Error: Detected unknown or invalid identifier.');
                }

                if (is_a($symbol, AbstractOpeningBracket::class)) {
                    $openBracketCounter++;
                }
                if (is_a($symbol, AbstractClosingBracket::class)) {
                    $openBracketCounter--;

                    // Make sure there are not too many closing brackets
                    if ($openBracketCounter < 0) {
                        throw new ParserException(
                            'Error: Found closing bracket that does not have an opening bracket.'
                        );
                    }
                }
            }

            // Make sure a function is not followed by a symbol that is not of type opening bracket
            if ($expectingOpeningBracket) {
                if (! is_a($symbol, AbstractOpeningBracket::class)) {
                    throw new ParserException(
                        'Error: Expected opening bracket (after a function) but got something else.'
                    );
                }

                $expectingOpeningBracket = false;
            } else {
                if (is_a($symbol, AbstractFunction::class)) {
                    $expectingOpeningBracket = true;
                }
            }

            $symbolNode = new SymbolNode($token, $symbol);

            $symbolNodes[] = $symbolNode;
        }

        // Make sure the term does not end with the name of a function but without an opening bracket
        if ($expectingOpeningBracket) {
            throw new ParserException(
                'Error: Expected opening bracket (after a function) but reached the end of the term.'
            );
        }

        // Make sure there are not too many opening brackets
        if ($openBracketCounter > 0) {
            throw new ParserException(
                'Error: There is at least one opening bracket that does not have a closing bracket.'
            );
        }

        return $symbolNodes;
    }

    /**
     * Expects a flat array of symbol nodes and (if possible) transforms
     * it to a tree of nodes. Cares for brackets.
     * Attention: Expects valid brackets!
     * Check the brackets before you call this method.
     *
     * @param SymbolNode[] $symbolNodes
     * @return AbstractNode[]
     * @throws ParserException
     */
    protected function createTreeByBrackets(array $symbolNodes)
    {
        $tree = [];
        $nodesInBrackets = []; // Symbol nodes inside level-0-brackets
        $openBracketCounter = 0;

        foreach ($symbolNodes as $index => $symbolNode) {
            if (! is_a($symbolNode, SymbolNode::class)) {
                throw new ParserException('Error: Expected node, got something else.');
            }

            if (is_a($symbolNode->getSymbol(), AbstractOpeningBracket::class)) {
                $openBracketCounter++;

                if ($openBracketCounter > 1) {
                    $nodesInBrackets[] = $symbolNode;
                }
            } elseif (is_a($symbolNode->getSymbol(), AbstractClosingBracket::class)) {
                $openBracketCounter--;

                // Found a closing bracket on level 0
                if ($openBracketCounter == 0) {
                    $subTree = $this->createTreeByBrackets($nodesInBrackets);

                    // Subtree can be empty for example if the term looks like this: "()" or "functioname()"
                    // But this is okay, we need to allow this so we can call functions without a parameter
                    $tree[] = new ContainerNode($subTree);
                } else {
                    $nodesInBrackets[] = $symbolNode;
                }
            } else {
                if ($openBracketCounter == 0) {
                    $tree[] = $symbolNode;
                } else {
                    $nodesInBrackets[] = $symbolNode;
                }
            }
        }

        return $tree;
    }

    /**
     * Replace [a SymbolNode that has a symbol of type AbstractFunction,
     * followed by a node of type ContainerNode] by a FunctionNode.
     * Expects the nodes not including any function nodes.
     *
     * @param AbstractNode[] $nodes
     * @return AbstractNode[]
     * @throws ParserException
     */
    protected function transformTreeByFunctions(array $nodes)
    {
        $transformedNodes = [];

        $functionSymbolNode = null;

        foreach ($nodes as $node) {
            if (is_a($node, ContainerNode::class)) {
                /** @var ContainerNode $node */
                $transformedChildNodes = $this->transformTreeByFunctions($node->getChildNodes());

                if ($functionSymbolNode !== null) {
                    $functionNode = new FunctionNode($transformedChildNodes, $functionSymbolNode);
                    $transformedNodes[] = $functionNode;
                    $functionSymbolNode = null;
                } else {
                    $node->setChildNodes($transformedChildNodes);
                    $transformedNodes[] = $node;
                }
            } elseif (is_a($node, SymbolNode::class)) {
                /** @var SymbolNode $node */
                $symbol = $node->getSymbol();
                if (is_a($symbol, AbstractFunction::class)) {
                    $functionSymbolNode = new $symbol;
                } else {
                    $transformedNodes[] = $node;
                }
            } else {
                throw new ParserException('Error: Expected array node or symbol node, got something else.');
            }
        }

        return $transformedNodes;
    }

    /**
     * Ensures the tree follows the grammar rules for terms
     *
     * @param array $nodes
     * @return void
     * @throws ParserException
     */
    protected function checkGrammar(array $nodes)
    {
        /*
         * TODO implement the missing parts of this method:
         * - make sure that separators are only in the child nodes of the array node of a function node
         */

        foreach ($nodes as $index => $node) {
            if (is_a($node, SymbolNode::class)) {
                /** @var $node SymbolNode */

                $symbol = $node->getSymbol();

                if (is_a($symbol, AbstractOperator::class)) {
                    /** @var $symbol AbstractOperator */

                    $posOfRightOperand = $index + 1;

                    // Make sure the operator is positioned left of a (potential) operand (=prefix notation).
                    // Example term: "-1"
                    if ($posOfRightOperand >= sizeof($nodes)) {
                        throw new ParserException('Error: Found operator that does not stand before an operand.');
                    }

                    $posOfLeftOperand = $index - 1;

                    $leftOperand = null;

                    // Operator is unary if positioned at the beginning of a term
                    if ($posOfLeftOperand >= 0) {
                        $leftOperand = $nodes[$posOfLeftOperand];

                        if (is_a($leftOperand, SymbolNode::class)) {
                            /** @var $leftOperand SymbolNode */
                            if (is_a($leftOperand->getSymbol(), AbstractOperator::class)) {
                                // Operator is unary if positioned right to another operator
                                $leftOperand = null;
                            }
                        }
                    }

                    // If null, the operator is unary
                    if ($leftOperand === null) {
                        if (! constant(get_class($symbol).'::OPERATES_UNARY')) {
                            throw new ParserException('Error: Found operator in unary notation that is not unary.');
                        }
                    } else {
                        if (! constant(get_class($symbol).'::OPERATES_BINARY')) {
                            throw new ParserException('Error: Found operator in binary notation that is not binary.');
                        }
                    }
                }
            } else {
                /** @var $node ContainerNode */
                $this->checkGrammar($node->getChildNodes());
            }
        }
    }

}