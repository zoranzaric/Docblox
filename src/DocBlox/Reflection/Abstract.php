<?php
/**
 * DocBlox
 *
 * @category   DocBlox
 * @package    Static_Reflection
 * @copyright  Copyright (c) 2010-2011 Mike van Riel / Naenius. (http://www.naenius.com)
 */

/**
 * Provides the basic functionality for every static reflection class.
 *
 * @category   DocBlox
 * @package    Static_Reflection
 * @subpackage Base
 * @author     Mike van Riel <mike.vanriel@naenius.com>
 */
abstract class DocBlox_Reflection_Abstract extends DocBlox_Core_Abstract
{
  /**
   * Stores the method name of the processing method for a token.
   *
   * The generation of method names may be a performance costly task and is quite often executed.
   * As such we cache the method names which are coming from tokens here in this array.
   *
   * @var string[]
   */
  private static $token_method_cache = array();

  /**
   * Stores the name for this Reflection object.
   *
   * @var string
   */
  protected $name      = 'Unknown';

  /**
   * Stores the start position by token index.
   *
   * @var int
   */
  protected $token_start = 0;

  /**
   * Stores the end position by token index.
   *
   * @var int
   */
  protected $token_end   = 0;

  /**
   * Stores the line where the initial token was found.
   *
   * @var int
   */
  protected $line_start  = 0;

  /**
   * Stores the name of the namespace to which this belongs.
   *
   * @var string
   */
  protected $namespace   = 'default';

  /**
   * Stores the aliases and full names of any defined namespace alias (T_USE).
   *
   * @var string[]
   */
  protected $namespace_aliases = array();

  /**
   * Main function which reads the token iterator and parses the current token.
   *
   * @param DocBlox_Token_Iterator $tokens The iterator with tokens.
   *
   * @return void
   */
  public function parseTokenizer(DocBlox_Token_Iterator $tokens)
  {
    if (!$tokens->current())
    {
      $this->log('>> No contents found to parse');
      return;
    }

    $this->debug('== Parsing token '.$tokens->current()->getName());
    $this->line_start = $tokens->current()->getLineNumber();

    // retrieve generic information about the class
    $this->processGenericInformation($tokens);

    list($start, $end) = $this->processTokens($tokens);
    $this->token_start = $start;
    $this->token_end   = $end;

    $this->debug('== Determined token index range to be '.$start.' => '.$end);

    $this->debugTimer('>> Processed all tokens');
  }

  /**
   * Processes the meta-data of the 'main' token.
   *
   * Example: for the DocBlox_Reflection_Function class this would be the name, parameters, etc.
   *
   * @abstract
   *
   * @param DocBlox_Token_Iterator $tokens The iterator with tokens.
   *
   * @return void
   */
  abstract protected function processGenericInformation(DocBlox_Token_Iterator $tokens);

  /**
   * Scans all tokens within the scope of the current token and invokes the process* methods.
   *
   * This is a base class which may be overridden in sub-classes to scan the scope of the current token
   * (i.e. the method body in case of the method)
   *
   * @param DocBlox_Token_Iterator $tokens iterator with the current position
   *
   * @return int[] Start and End token id
   */
  protected function processTokens(DocBlox_Token_Iterator $tokens)
  {
    return array($tokens->key(), $tokens->key());
  }

  /**
   * Processes the current token and invokes the correct process* method.
   *
   * Tokens are automatically parsed by invoking a process* method (i.e. processFunction for a T_FUNCTION).
   * If a method, which conforms to the standard above, does not exist the token is ignored.
   *
   * @param DocBlox_Token         $token  The specific token which needs processing.
   * @param DocBlox_Token_Iterator $tokens The iterator with tokens.
   *
   * @return void
   */
  protected function processToken(DocBlox_Token $token, DocBlox_Token_Iterator $tokens)
  {
    static $token_method_exists_cache = array();

    // cache method name; I expect to find this a lot
    $token_name = $token->getName();
    if (!isset(self::$token_method_cache[$token_name]))
    {
      self::$token_method_cache[$token_name] = 'process'.str_replace(' ', '', ucwords(strtolower(substr(str_replace('_', ' ', $token_name), 2))));
    }

    // cache the method_exists calls to speed up processing
    $method_name = self::$token_method_cache[$token_name];
    if (!isset($token_method_exists_cache[$method_name]))
    {
      $token_method_exists_cache[$method_name] = method_exists($this, $method_name);
    }

    // if method exists; parse the token
    if ($token_method_exists_cache[$method_name])
    {
      $this->$method_name($tokens);
    }
  }

  /**
   * Find the Type for this object.
   *
   * Please note that the iterator cursor does not change due to this method
   *
   * @param  DocBlox_Token_Iterator $tokens
   * @return string|null
   */
  protected function findType(DocBlox_Token_Iterator $tokens)
  {
    // first see if there is a string at most 5 characters back
    $type = $tokens->findPreviousByType(T_STRING, 5, array(',', '('));

    // if none found, check if there is an array at most 5 places back
    if (!$type)
    {
      $type = $tokens->findPreviousByType(T_ARRAY, 5, array(',', '('));
    }

    // if anything is found, return the content
    return $type ? $type->getContent() : null;
  }

  /**
   * Find the Default value for this object.
   *
   * Usually used with variables or arguments.
   * Please note that the iterator cursor does not change due to this method
   *
   * @param  DocBlox_Token_Iterator $tokens
   * @return string|null
   */
  protected function findDefault(DocBlox_Token_Iterator $tokens)
  {
    // check if a string is found
    $default_token        = $tokens->findNextByType(T_STRING, 5, array(',', ')'));
    if (!$default_token)
    {
      // check for a constant
      $default_token      = $tokens->findNextByType(T_CONSTANT_ENCAPSED_STRING, 5, array(',', ')'));
    }
    if (!$default_token)
    {
      // check for a number
      $default_token      = $tokens->findNextByType(T_LNUMBER, 5, array(',', ')'));
    }
    if (!$default_token)
    {
      // check for an array definition
      $default_token      = $tokens->findNextByType(T_ARRAY, 5, array(',', ')'));
    }

    // remove any surrounding single or double quotes before returning the data
    return $default_token ? trim($default_token->getContent(), '\'"') : null;
  }

  /**
   * Determine whether this token has the abstract keyword.
   *
   * Please note that the iterator cursor does not change due to this method
   *
   * @param  DocBlox_Token_Iterator $tokens
   * @return DocBlox_Token|null
   */
  protected function findAbstract(DocBlox_Token_Iterator $tokens)
  {
    return $tokens->findPreviousByType(T_ABSTRACT, 5, array('}'));
  }

  /**
   * Determine whether this token has the final keyword.
   *
   * Please note that the iterator cursor does not change due to this method
   *
   * @param  DocBlox_Token_Iterator $tokens
   * @return DocBlox_Token|null
   */
  protected function findFinal(DocBlox_Token_Iterator $tokens)
  {
    return $tokens->findPreviousByType(T_FINAL, 5, array('}'));
  }

  /**
   * Determine whether this token has the static keyword.
   *
   * Please note that the iterator cursor does not change due to this method
   *
   * @param  DocBlox_Token_Iterator $tokens
   * @return DocBlox_Token|null
   */
  protected function findStatic(DocBlox_Token_Iterator $tokens)
  {
    return $tokens->findPreviousByType(T_STATIC, 5, array('{', ';'));
  }

  /**
   * Searches for visibility specifiers with the current token.
   *
   * @param DocBlox_Token_Iterator $tokens Token iterator to search in.
   *
   * @return string public|private|protected
   */
  protected function findVisibility(DocBlox_Token_Iterator $tokens)
  {
    $result = 'public';
    $result = $tokens->findPreviousByType(T_PRIVATE, 5, array('{', ';')) ? 'private' : $result;
    $result = $tokens->findPreviousByType(T_PROTECTED, 5, array('{', ';')) ? 'protected' : $result;

    return $result;
  }

  /**
   * Sets the name for this Reflection Object.
   *
   * @throws InvalidArgumentException
   * @param  string $name
   * @return void
   */
  public function setName($name)
  {
    if (!is_string($name))
    {
      throw new InvalidArgumentException('Expected name to be a string');
    }

    $this->name = $name;
  }

  /**
   * Returns the name for this Reflection object.
   *
   * @return string
   */
  public function getName()
  {
    return $this->name;
  }

  /**
   * Sets the name of the namespace to which this belongs.
   *
   * @throws InvalidArgumentException
   * @param  $namespace
   * @return void
   */
  public function setNamespace($namespace)
  {
    if (!is_string($namespace))
    {
      throw new InvalidArgumentException('Expected the namespace to be a string');
    }

    $this->namespace = $namespace;
  }

  /**
   * Returns the name of the namespace to which this belongs.
   *
   * @return string
   */
  public function getNamespace()
  {
    return $this->namespace;
  }

  /**
   * Sets the name of the namespace to which this belongs.
   *
   * @throws InvalidArgumentException
   * @param  $namespace
   * @return void
   */
  public function setNamespaceAliases($namespace_aliases)
  {
    if (!is_array($namespace_aliases))
    {
      throw new InvalidArgumentException('Expected the namespace alaises to be an array of strings');
    }

    $this->namespace_aliases = $namespace_aliases;
  }

  /**
   * Returns the namespace aliases which can be applied to the types in this object.
   *
   * @return string
   */
  public function getNamespaceAliases()
  {
    return $this->namespace_aliases;
  }

  /**
   * Returns the line number where this token starts.
   *
   * @return int
   */
  public function getLineNumber()
  {
    return $this->line_start;
  }

  /**
   * Getter; returns the token id which identifies the start of this object.
   *
   * @return int
   */
  public function getStartTokenId()
  {
    return $this->token_start;
  }

  /**
   * Returns the token id which identifies the end of the object.
   *
   * @return int
   */
  public function getEndTokenId()
  {
    return $this->token_end;
  }

  /**
   * Helper used to merge a given XML string into a given DOMDocument.
   *
   * @param DOMDocument $origin Destination to merge the XML into.
   * @param string      $xml    The XML to merge with the document.
   *
   * @return void
   */
  protected function mergeXmlToDomDocument(DOMDocument $origin, $xml)
  {
    $dom_arguments = new DOMDocument();
    $dom_arguments->loadXML(trim($xml));

    $this->mergeDomDocuments($origin, $dom_arguments);
  }

  /**
   * Helper method which merges a $document into $origin.
   *
   * @param DOMDocument $origin   The document to accept the changes.
   * @param DOMDocument $document The changes which are to be merged into the origin.
   *
   * @return void
   */
  protected function mergeDomDocuments(DOMDocument $origin, DOMDocument $document)
  {
    $xpath = new DOMXPath($document);
    $qry = $xpath->query('/*');
    for ($i = 0; $i < $qry->length; $i++)
    {
      $origin->documentElement->appendChild($origin->importNode($qry->item($i), true));
    }
  }

  /**
   * Returns an XML representation of this object.
   *
   * @abstract
   *
   * @return string
   */
  abstract public function __toXml();

  /**
   * Default behavior of the toString method is to return the name of this reflection.
   *
   * @return string
   */
  public function __toString()
  {
    return $this->getName();
  }

}