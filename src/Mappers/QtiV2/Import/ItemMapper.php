<?php

namespace Learnosity\Mappers\QtiV2\Import;

use Exception;
use Learnosity\Entities\BaseQuestionType;
use Learnosity\Entities\Question;
use Learnosity\Exceptions\MappingException;
use qtism\data\AssessmentItem;
use qtism\data\content\BlockCollection;
use qtism\data\content\ItemBody;
use qtism\data\content\Math;
use qtism\data\content\RubricBlock;
use qtism\data\processing\ResponseProcessing;
use qtism\data\QtiComponent;
use qtism\data\storage\xml\XmlCompactDocument;

class ItemMapper
{
    private $xmlDocument;
    private $exceptions = [];
    private $supportedInteractions = [
        'inlineChoiceInteraction',
        'choiceInteraction',
        'extendedTextInteraction',
        'textEntryInteraction'
    ];
    private $regularItemBuilder;
    private $mergedItemBuilder;
    private $hasMathML = false;

    public function __construct(XmlCompactDocument $document,
                                MergedItemBuilder $mergedItemBuilder,
                                RegularItemBuilder $regularItemBuilder)
    {
        $this->xmlDocument = $document;
        $this->exceptions = [];
        $this->mergedItemBuilder = $mergedItemBuilder;
        $this->regularItemBuilder = $regularItemBuilder;
    }

    public function getExceptions()
    {
        return $this->exceptions;
    }

    public function parse($xmlString)
    {
        $this->xmlDocument->loadFromString($xmlString);

        /* @var $assessmentItem AssessmentItem */
        $assessmentItem = $this->validateAssessmentItem($this->xmlDocument->getDocumentComponent());
        $responseProcessingTemplate = $this->getResponseProcessingTemplate($assessmentItem->getResponseProcessing());

        /** @var ItemBody $itemBody */
        $itemBody = $assessmentItem->getItemBody();
        $itemBody = $this->filterItemBody($itemBody);

        // Mapping interactions
        $interactionComponents = $itemBody->getComponentsByClassName($this->supportedInteractions, true);
        if (!$interactionComponents || count($interactionComponents) === 0) {
            $this->exceptions[] =
                new MappingException('No supported interactions could be found', MappingException::CRITICAL);
            return null;
        }

        $responseDeclarations = $assessmentItem->getComponentsByClassName('responseDeclaration', true);

        $mergedMapResult = $this->mergedItemBuilder->map(
            $assessmentItem->getIdentifier(),
            $itemBody,
            $interactionComponents,
            $responseDeclarations,
            $responseProcessingTemplate
        );

        $mapper = ($mergedMapResult) ? $this->mergedItemBuilder : $this->regularItemBuilder;

        if (!$mergedMapResult) {
            $mapper->map(
                $assessmentItem->getIdentifier(),
                $itemBody,
                $interactionComponents,
                $responseDeclarations,
                $responseProcessingTemplate
            );
        }

        $item = $mapper->getItem();
        $this->exceptions = array_merge($this->exceptions, $mapper->getExceptions());
        if ($assessmentItem->getTitle()) {
            $item->set_description($assessmentItem->getTitle());
        }

        // Add `is_math` to questions if needed
        $questions = $mapper->getQuestions();
        if ($this->hasMathML) {
            /** @var Question $question */
            foreach ($questions as &$question) {
                /** @var BaseQuestionType $questionType */
                $questionType = $question->get_data();
                if (method_exists($questionType, 'set_is_math')) {
                    $questionType->set_is_math(true);
                }
            }
        }
        return [$item, $questions, $this->getExceptionMessages()];
    }

    private function filterItemBody(ItemBody $itemBody)
    {
        // TODO: Tidy up, yea remove those mathML stuffs
        foreach ($itemBody->getIterator() as $component) {
            if ($component instanceof Math) {
                $element = $component->getXml()->documentElement;
                $element->removeAttributeNS($element->namespaceURI, $element->prefix);
                $component->setXmlString($element->ownerDocument->saveHTML());
                $component->setTargetNamespace('');
                $this->hasMathML = true;
            }
        }

        // TODO: Yea, we ignore rubric but what happen if the rubric is deep inside nested
        $newCollection = new BlockCollection();
        $itemBodyNew = new ItemBody();

        $hasRubric = false;
        /** @var QtiComponent $component */
        foreach ($itemBody->getContent() as $key => $component) {
            if (!($component instanceof RubricBlock)) {
                $newCollection->attach($component);
            } else {
                $hasRubric = true;
            }
        }
        if ($hasRubric) {
            $this->exceptions[] = new MappingException('Does not support <rubricBlock>. Ignoring <rubricBlock>');
        }
        $itemBodyNew->setContent($newCollection);
        return $itemBodyNew;
    }

    private function getExceptionMessages()
    {
        $result = [];
        /** @var Exception $exception */
        foreach ($this->exceptions as $exception) {
            $result[] = $exception->getMessage();
        }
        return $result;
    }

    private function validateAssessmentItem(AssessmentItem $assessmentItem)
    {
        if ($assessmentItem->getOutcomeDeclarations()->count()) {
            $this->exceptions[] = new MappingException('Ignoring <outcomeDeclaration> on <assessmentItem>. Generally we mapped <defaultValue> to 0');
        }
        if ($assessmentItem->getTemplateDeclarations()->count()) {
            throw new MappingException('Does not support <templateDeclaration> on <assessmentItem>. Ignoring <templateDeclaration>', MappingException::CRITICAL);
        }
        if (!empty($assessmentItem->getTemplateProcessing())) {
            throw new MappingException('Does not support <templateProcessing> on <assessmentItem>. Ignoring <templateProcessing>', MappingException::CRITICAL);
        }
        if ($assessmentItem->getModalFeedbacks()->count()) {
            $this->exceptions[] = new MappingException('Ignoring <modalFeedback> on <assessmentItem>');
        }
        if ($assessmentItem->getStylesheets()->count()) {
            $this->exceptions[] = new MappingException('Ignoring <stylesheet> on <assessmentItem>');
        }
        return $assessmentItem;
    }

    private function getResponseProcessingTemplate(ResponseProcessing $responseProcessing = null)
    {
        if (!empty($responseProcessing)) {
            if ($responseProcessing->getResponseRules()->count()) {
                $this->exceptions[] = new MappingException('Does not support custom response processing on <responseProcessing>. Ignoring <responseProcessing>');
            }
            if (!empty($responseProcessing->getTemplateLocation())) {
                $this->exceptions[] = new MappingException('Does not support \'templateLocation\' on <responseProcessing>. Ignoring <responseProcessing>');
            }
            if (!empty($responseProcessing->getTemplate())) {
                $responseProcessingTemplate = ResponseProcessingTemplate::getFromTemplateUrl($responseProcessing->getTemplate());
                if (empty($responseProcessingTemplate)) {
                    $this->exceptions[] = new MappingException('Does not support custom response processing templates. Ignoring <responseProcessing>');
                }
                return $responseProcessingTemplate;
            }
        }
        return null;
    }
}
