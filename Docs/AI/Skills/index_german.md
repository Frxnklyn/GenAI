# KI-Skills

[English](index.md)

KI-Skills sind wiederverwendbare, nicht versionierte Bausteine für Agenten. Ein Skill kann Instructions, Concepts und Tools enthalten. Alle drei Bestandteile sind optional.

Skills werden in Power UI unter **Administration > AI > AI Skills** verwaltet. Ein Skill kann global oder einer App zugeordnet sein. Jeder Datensatz besitzt außerdem einen PHP-Prototyp sowie optionale Markdown-Instructions und eine optionale UXON-Konfiguration. `GenericSkill` ist der Standardprototyp.

## Skill verwenden

Skills werden in der Skill-Liste einer Agent-Version zugeordnet. Der lokale Skill-Alias wird automatisch zum Placeholder. Um die Instructions eines Skills mit Alias `test` in den Agent-Prompt einzufügen, wird er wie ein Concept verwendet:

```markdown
Du bist ein hilfreicher Assistent.

[#test#]
```

Der Platzhalter ist optional. Fehlt `[#test#]`, werden die Skill-Instructions nicht in den Prompt eingefügt. Der Skill wird trotzdem geladen und seine Tools bleiben für den Agenten verfügbar.

## Skill-Konfiguration

Ein Skill wird ähnlich wie ein normaler Agent konfiguriert: Die Instructions enthalten den Prompt-Text, während `CONFIG_UXON` die strukturierte Konfiguration enthält. Siehe die UXON-Prototypen für [`GenericAssistant`](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CAgents%5CGenericAssistant) und [`GenericSkill`](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CSkills%5CGenericSkill).

Der Standardprototyp `GenericSkill` akzeptiert folgende optionale Eigenschaften in `CONFIG_UXON`:

- `concepts`: benannte Concept-Konfigurationen für die Skill-Instructions.
- `skills`: benannte Skills, deren gerenderte Instructions über lokale Platzhalter eingefügt werden können.
- `tools`: benannte Tool-Konfigurationen, die dem Agenten bereitgestellt werden.

### Instructions eines Skills schreiben

Die Instructions jedes Skills sollten mit genau einer `#`-Überschrift (Markdown "Überschrift 1") beginnen, die den Skill benennt, zum Beispiel `# NOTES`. So lässt sich jeder Skill leicht erkennen, wenn mehrere Skills im selben Agent-Prompt kombiniert werden.

Eigene Trennlinien oder "Anfang/Ende des Abschnitts"-Markierungen um die Instructions herum sind nicht nötig - das übernimmt `GenericSkill` automatisch. Vereinfacht gesagt: Beim Rendern für den Prompt umschließt `GenericSkill` die Instructions mit einer unsichtbaren Markierung (einem HTML-Kommentar), die ein Mensch beim Lesen des dargestellten Markdown-Textes nie zu sehen bekommt, die aber Teil des Textes bleibt, den die KI tatsächlich liest. Dadurch kann die KI zuverlässig erkennen, wo die Instructions eines Skills enden und der umgebende Prompt weitergeht, ohne dass der für Menschen sichtbare Text unübersichtlich wird.

Wird ein Skill ausschließlich verschachtelt in einem anderen Skill oder Concept verwendet (und nie direkt einem Agenten zugeordnet), sollte für diese Einbindung `use_instruction_boundary` auf `false` gesetzt werden. Sonst umschließt der äußere Skill Text, der bereits umschlossen ist - eine unsichtbare Markierung steckt dann in der anderen, was die äußere Markierung nicht nur überflüssig macht, sondern kaputt gehen lässt:

```json
{
    "skills": {
        "read": {
            "alias": "my.App.Read",
            "use_instruction_boundary": false
        }
    }
}
```

Concepts ergänzen einen Skill um Hintergrundinformationen. Verschachtelte Skills können weiterhin unter `skills` im eigenen `CONFIG_UXON` des Skills konfiguriert werden:

```json
{
    "skills": {
        "lookup": {
            "alias": "my.App.lookup"
        }
    }
}
```

Die Tools aus `my.App.lookup` werden automatisch in den aktuellen Skill übernommen. Um zusätzlich seine Instructions zu verwenden, wird der lokale Name `lookup` wie ein Concept in die Instructions des aktuellen Skills eingefügt:

```markdown
Verwende die folgenden Anweisungen für die Suche:

[#lookup#]
```

Der eingebundene Skill bereitet zuerst seine eigenen Concepts und verschachtelten Skills auf, bevor seine Instructions eingefügt werden. Der aktuelle Skill kann die übernommenen Instructions und Tools danach genauso wie ein Agent verwenden. Fehlt der Platzhalter, werden die Tools trotzdem übernommen.

Tool-Namen sollten möglichst eindeutig sein. Kommt derselbe Name mehrfach vor, hat der später eingebundene Skill Vorrang. Ein direkt im aktuellen Skill konfiguriertes Tool hat Vorrang vor seinen eingebundenen Skills. Ein direkt am Agenten konfiguriertes Tool hat Vorrang vor allen Skill-Tools. Wenn dabei ein Tool ersetzt wird, wird eine Warnung in der Conversation gespeichert.

## Eigene Prototypen

Apps können eigene Skill-Prototypen unter `AI/Skills/*.php` bereitstellen. Ein Prototyp muss `AiSkillInterface` implementieren; die Erweiterung des Verhaltens von `GenericSkill` ist der übliche Ausgangspunkt. Der ausgewählte Prototyp bestimmt die UXON-Eigenschaften im Power-UI-Editor.

## Einen Skill aus einem Concept verwenden

Ein Concept kann die gerenderten Instructions eines Skills genauso einfügen, wie `AgentInstructionsConcept` die Instructions eines anderen Agenten einfügt. Dafür wird [`SkillInstructionsConcept`](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CConcepts%5CSkillInstructionsConcept) mit `skill_alias` verwendet, zum Beispiel um mehrere Skills in einer Übersicht zusammenzufassen. Referenzieren Sie es wie jedes andere Concept über `alias`, nicht über `class`. Wie bei verschachtelten Skills sollte `use_instruction_boundary` auf `false` gesetzt werden, wenn der Platzhalter des Concepts bereits von einem umgebenden Skill umschlossen wird:

```json
{
    "concepts": {
        "notes_overview": {
            "alias": "axenox.GenAI.SkillInstructionsConcept",
            "skill_alias": "my.App.Notes",
            "use_instruction_boundary": false
        }
    }
}
```