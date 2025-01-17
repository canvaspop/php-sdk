<?php
/**
 * Copyright 2021, Optimizely
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace Optimizely;

use Optimizely\Logger\LoggerInterface;
use Optimizely\Logger\NoOpLogger;

class OptimizelyUserContext implements \JsonSerializable
{
    private $optimizelyClient;
    private $userId;
    private $attributes;
    private $forcedDecisions;

    public function __construct(Optimizely $optimizelyClient, $userId, array $attributes = [], $forcedDecisions = null)
    {
        $this->optimizelyClient = $optimizelyClient;
        $this->userId = $userId;
        $this->attributes = $attributes;
        $this->forcedDecisions = $forcedDecisions;
    }

    public function setForcedDecision($context, $decision)
    {
        // check if SDK is ready
        if (!$this->optimizelyClient->isValid()) {
            return false;
        }
        $flagKey = $context->getFlagKey();
        if (!isset($flagKey)) {
            return false;
        }
        $index = $this->findExistingRuleAndFlagKey($context);
        if ($index != -1) {
            $this->forcedDecisions[$index]->setOptimizelyForcedDecision($decision);
        } else {
            if (!$this->forcedDecisions) {
                $this->forcedDecisions = array();
            }
            array_push($this->forcedDecisions, new ForcedDecision($context, $decision));
        }
        return true;
    }

    public function getForcedDecision($context)
    {
        // check if SDK is ready
        if (!$this->optimizelyClient->isValid()) {
            return null;
        }
        return $this->findForcedDecision($context);
    }

    public function removeForcedDecision($context)
    {
        // check if SDK is ready
        if (!$this->optimizelyClient->isValid()) {
            return false;
        }
        $index = $this->findExistingRuleAndFlagKey($context);
        if ($index != -1) {
            array_splice($this->forcedDecisions, $index, 1);
            return true;
        }
        return false;
    }

    public function removeAllForcedDecisions()
    {
        // check if SDK is ready
        if (!$this->optimizelyClient->isValid()) {
            return false;
        }

        $this->forcedDecisions = [];
        return true;
    }

    public function findValidatedForcedDecision($context)
    {
        $decideReasons = [];
        $flagKey = $context->getFlagKey();
        $ruleKey = $context->getRuleKey();
        $variationKey = $this->findForcedDecision($context);
        $variation = null;
        if ($variationKey) {
            $variation = $this->optimizelyClient->getFlagVariationByKey($flagKey, $variationKey);
            if ($variation) {
                array_push($decideReasons, 'Decided by forced decision.');
                array_push($decideReasons, sprintf('Variation (%s) is mapped to %s and user (%s) in the forced decision map.', $variationKey, $ruleKey? 'flag ('.$flagKey.'), rule ('.$ruleKey.')': 'flag ('.$flagKey.')', $this->userId));
            } else {
                array_push($decideReasons, sprintf('Invalid variation is mapped to %s and user (%s) in the forced decision map.', $ruleKey? 'flag ('.$flagKey.'), rule ('.$ruleKey.')': 'flag ('.$flagKey.')', $this->userId));
            }
        }
        return [$variation, $decideReasons];
    }

    private function findExistingRuleAndFlagKey($context)
    {
        if ($this->forcedDecisions) {
            for ($index = 0; $index < count($this->forcedDecisions); $index++) {
                if ($this->forcedDecisions[$index]->getOptimizelyDecisionContext()->getFlagKey() == $context->getFlagKey() &&  $this->forcedDecisions[$index]->getOptimizelyDecisionContext()->getRuleKey() == $context->getRuleKey()) {
                    return $index;
                }
            }
        }
        return -1;
    }

    public function findForcedDecision($context)
    {
        $foundVariationKey = null;
        if (!isset($this->forcedDecisions)) {
            return null;
        }
        if (count($this->forcedDecisions) == 0) {
            return null;
        }
        $index = $this->findExistingRuleAndFlagKey($context);
        if ($index != -1) {
            $foundVariationKey = $this->forcedDecisions[$index]->getOptimizelyForcedDecision()->getVariationKey();
        }
        return $foundVariationKey;
    }
    protected function copy()
    {
        return new OptimizelyUserContext($this->optimizelyClient, $this->userId, $this->attributes, $this->forcedDecisions);
    }

    public function setAttribute($key, $value)
    {
        $this->attributes[$key] = $value;
    }

    public function decide($key, array $options = [])
    {
        return $this->optimizelyClient->decide($this->copy(), $key, $options);
    }

    public function decideForKeys(array $keys, array $options = [])
    {
        return $this->optimizelyClient->decideForKeys($this->copy(), $keys, $options);
    }

    public function decideAll(array $options = [])
    {
        return $this->optimizelyClient->decideAll($this->copy(), $options);
    }

    public function trackEvent($eventKey, array $eventTags = [])
    {
        $eventTags = $eventTags ?: null;
        return $this->optimizelyClient->track($eventKey, $this->userId, $this->attributes, $eventTags);
    }

    public function getUserId()
    {
        return $this->userId;
    }

    public function getAttributes()
    {
        return $this->attributes;
    }

    public function getOptimizely()
    {
        return $this->optimizelyClient;
    }

    public function jsonSerialize()
    {
        return [
            'userId' => $this->userId,
            'attributes' => $this->attributes
        ];
    }
}
class ForcedDecision
{
    private $optimizelyDecisionContext;
    private $optimizelyForcedDecision;

    public function __construct($optimizelyDecisionContext, $optimizelyForcedDecision)
    {
        $this->optimizelyDecisionContext = $optimizelyDecisionContext;
        $this->setOptimizelyForcedDecision($optimizelyForcedDecision);
    }

    /**
     * @return mixed
     */
    public function getOptimizelyDecisionContext()
    {
        return $this->optimizelyDecisionContext;
    }

    /**
     * @return mixed
     */
    public function getOptimizelyForcedDecision()
    {
        return $this->optimizelyForcedDecision;
    }

    public function setOptimizelyForcedDecision($optimizelyForcedDecision)
    {
        $this->optimizelyForcedDecision = $optimizelyForcedDecision;
    }
}

class OptimizelyDecisionContext
{
    private $flagKey;
    private $ruleKey;

    public function __construct($flagKey, $ruleKey)
    {
        $this->flagKey = $flagKey;
        $this->ruleKey = $ruleKey;
    }

    /**
     * @return mixed
     */
    public function getFlagKey()
    {
        return $this->flagKey;
    }

    /**
     * @return mixed
     */
    public function getRuleKey()
    {
        return $this->ruleKey;
    }
}
class OptimizelyForcedDecision
{
    private $variationKey;

    public function __construct($variationKey)
    {
        $this->setVariationKey($variationKey);
    }

    public function setVariationKey($variationKey)
    {
        if (isset($variationKey) && trim($variationKey) !== '') {
            $this->variationKey = $variationKey;
        }
    }

    /**
     * @return mixed
     */
    public function getVariationKey()
    {
        return $this->variationKey;
    }
}
