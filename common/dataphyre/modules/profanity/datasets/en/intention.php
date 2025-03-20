<?php

$cached_profanity_rulesets['intention']=[
    "ruleset_type" => "intention",
    "rules" => [
        "i need" => [
            "base_weight" => 3,
            "followed_by" => [
                "words" => ["a", "an", "some", "the", "to", "for"],
                "weight" => 2
            ],
            "not_followed_by" => [
                "words" => ["help", "you", "advice"],
                "weight" => -2
            ]
        ],
        "find me" => [
            "base_weight" => 4,
            "followed_by" => [
                "words" => ["a", "an", "some", "the", "to", "for"],
                "weight" => 3
            ],
            "not_followed_by" => [
                "words" => ["a way", "a solution", "an answer"],
                "weight" => -3
            ]
        ],
        "show me" => [
            "base_weight" => 3,
            "followed_by" => [
                "words" => ["the best", "a list of", "some", "all"],
                "weight" => 2
            ],
            "not_followed_by" => [
                "words" => ["how to", "a tutorial"],
                "weight" => -3
            ]
        ],
        "can you recommend" => [
            "base_weight" => 5,
            "followed_by" => [
                "words" => ["a", "some", "an", "the"],
                "weight" => 3
            ],
            "not_followed_by" => [
                "words" => ["a course", "an expert"],
                "weight" => -2
            ]
        ],
        "please find" => [
            "base_weight" => 4,
            "followed_by" => [
                "words" => ["a", "an", "some", "the"],
                "weight" => 3
            ],
            "not_followed_by" => [
                "words" => ["a document", "my file"],
                "weight" => -3
            ]
        ],
        "what is the best" => [
            "base_weight" => 5,
            "followed_by" => [
                "words" => ["price for", "option for", "deal on", "value"],
                "weight" => 3
            ],
            "not_followed_by" => [
                "words" => ["way to", "method for"],
                "weight" => -3
            ]
        ],
        "i am looking for" => [
            "base_weight" => 4,
            "followed_by" => [
                "words" => ["a", "an", "some", "the"],
                "weight" => 3
            ],
            "not_followed_by" => [
                "words" => ["an idea", "advice"],
                "weight" => -2
            ]
        ],
        "help me find" => [
            "base_weight" => 4,
            "followed_by" => [
                "words" => ["a", "an", "some", "the"],
                "weight" => 3
            ],
            "not_followed_by" => [
                "words" => ["an answer", "a solution"],
                "weight" => -3
            ]
        ],
        "suggest me" => [
            "base_weight" => 4,
            "followed_by" => [
                "words" => ["a", "an", "some", "the"],
                "weight" => 3
            ],
            "not_followed_by" => [
                "words" => ["a strategy", "a solution"],
                "weight" => -3
            ]
        ],
        "is there a good" => [
            "base_weight" => 3,
            "followed_by" => [
                "words" => ["deal on", "price for", "option for"],
                "weight" => 2
            ],
            "not_followed_by" => [
                "words" => ["way to", "method for"],
                "weight" => -2
            ]
        ]
    ]
];
