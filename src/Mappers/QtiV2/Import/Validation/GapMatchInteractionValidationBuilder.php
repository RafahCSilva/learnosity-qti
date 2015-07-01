<?php

namespace Learnosity\Mappers\QtiV2\Import\Validation;

use Learnosity\Entities\QuestionTypes\clozeassociation_validation;
use Learnosity\Entities\QuestionTypes\clozeassociation_validation_alt_responses_item;
use Learnosity\Entities\QuestionTypes\clozeassociation_validation_valid_response;
use Learnosity\Exceptions\MappingException;
use Learnosity\Mappers\QtiV2\Import\ResponseProcessingTemplate;
use Learnosity\Utils\ArrayUtil;
use qtism\common\datatypes\DirectedPair;
use qtism\data\state\ResponseDeclaration;

class GapMatchInteractionValidationBuilder
{
    private $exceptions = [];
    private $validation = null;

    public function __construct(
        array $gapIdentifiers,
        array $possibleResponses,
        ResponseDeclaration $responseDeclaration = null,
        ResponseProcessingTemplate $responseProcessingTemplate = null
    ) {
        if (!empty($responseProcessingTemplate) && ! empty($responseDeclaration)) {
            $template = $responseProcessingTemplate->getTemplate();
            if ($template === ResponseProcessingTemplate::MATCH_CORRECT) {
                $this->validation = $this->buildMatchCorrectValidation($gapIdentifiers, $possibleResponses, $responseDeclaration);
            } elseif ($template === ResponseProcessingTemplate::MAP_RESPONSE) {
                $this->validation = $this->buildMapResponseValidation($gapIdentifiers, $possibleResponses, $responseDeclaration);
            } else {
                $this->exceptions[] = new MappingException(
                    'Does not support template ' . $template .
                    ' on <responseProcessing>'
                );
            }
        }
    }

    private function buildMatchCorrectValidation(array $gapIdentifiers, array $possibleResponses, ResponseDeclaration $responseDeclaration)
    {
        $gapIdentifiersIndexMap = array_flip($gapIdentifiers);
        $validResponses = [];
        foreach ($responseDeclaration->getCorrectResponse()->getValues() as $value) {
            /** @var DirectedPair $valuePair */
            $valuePair = $value->getValue();
            $responseValue = $possibleResponses[$valuePair->getFirst()];
            $responseIndex = $gapIdentifiersIndexMap[$valuePair->getSecond()];

            // Build valid response array in the correct order matching the `gap` elements
            $validResponses[$responseIndex][] = $responseValue;
        }
        ksort($validResponses);
        $combinationValidResponse = ArrayUtil::mutateResponses($validResponses);

        // First response pair shall be mapped to `valid_response`
        $firstValidResponseValue = array_shift($combinationValidResponse);
        $validResponse = new clozeassociation_validation_valid_response();
        $validResponse->set_score(1);
        $validResponse->set_value($firstValidResponseValue);

        // Others go in `alt_responses`
        $altResponses = [];
        foreach ($combinationValidResponse as $otherResponseValues) {
            $item = new clozeassociation_validation_alt_responses_item();
            $item->set_score(1);
            $item->set_value($otherResponseValues);
            $altResponses[] = $item;
        }

        $validation = new clozeassociation_validation();
        $validation->set_scoring_type('exactMatch');
        $validation->set_valid_response($validResponse);

        if (!empty($altResponses)) {
            $validation->set_alt_responses($altResponses);
        }
        return $validation;
    }

    private function buildMapResponseValidation(array $gapIdentifiers, array $possibleResponses, ResponseDeclaration $responseDeclaration)
    {
        // TODO: Left this intentionally unfinished because I need to work on something else
        $validation = new clozeassociation_validation();
        $validation->set_scoring_type('exactMatch');
        $validation->set_valid_response(new clozeassociation_validation_valid_response());

        if (!empty($altResponses)) {
            $validation->set_alt_responses($altResponses);
        }
        return $validation;
    }

    public function getExceptions()
    {
        return $this->exceptions;
    }

    public function getValidation()
    {
        return $this->validation;
    }
}
