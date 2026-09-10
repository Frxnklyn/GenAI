# AI skills

[Deutsch](index_german.md)

AI skills are reusable, non-versioned building blocks for agents. A skill can contain instructions, concepts, and tools. All three parts are optional.

Skills are managed in Power UI under **Administration > AI > AI Skills**. A skill can be global or owned by an app. Each record also has a PHP prototype, optional Markdown instructions, and optional UXON configuration. `GenericSkill` is the standard prototype.

## Using a skill

Assign skills in the skill list of an agent version. The local skill alias automatically becomes the placeholder. To include the instructions of a skill with alias `test` in the agent prompt, use it like a concept:

```markdown
You are a helpful assistant.

[#test#]
```

The placeholder is optional. If `[#test#]` is absent, the skill instructions are not added to the prompt. The skill is still loaded and its tools remain available to the agent.

## Skill configuration

A skill is configured similarly to a normal agent: its instructions contain the prompt text, while `CONFIG_UXON` contains its structured configuration. See the UXON prototypes for [`GenericAssistant`](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CAgents%5CGenericAssistant) and [`GenericSkill`](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CSkills%5CGenericSkill).

The standard `GenericSkill` accepts these optional properties in `CONFIG_UXON`:

- `concepts`: named concept configurations used inside the skill instructions.
- `skills`: named skills whose rendered instructions can be inserted through local placeholders.
- `tools`: named tool configurations contributed to the agent.

### Writing skill instructions

Instructions of every skill should start with a single `#` heading (Markdown "Heading 1") naming the skill, for example `# NOTES`. This makes every skill easy to recognize when several skills are combined in one agent prompt.

Do not add your own separator lines or "start/end of section" markers around the instructions - `GenericSkill` adds this automatically. Put in plain terms: when the instructions are rendered for the prompt, `GenericSkill` wraps them in an invisible marker (an HTML comment) that a human reading the resulting Markdown will never see, but that stays part of the text the AI actually reads. This lets the AI reliably tell where one skill's instructions end and the surrounding prompt continues, without cluttering the text that a person would see.

When a skill is only ever consumed nested inside another skill or concept (never assigned directly to an agent), set `use_instruction_boundary` to `false` for that inclusion. Otherwise the outer skill would wrap text that is already wrapped, nesting one invisible marker inside another - which breaks the outer marker instead of just being redundant:

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

Concepts add background information to a skill. Nested skills can still be configured below `skills` inside the skill's own `CONFIG_UXON`:

```json
{
    "skills": {
        "lookup": {
            "alias": "my.App.lookup"
        }
    }
}
```

The tools of `my.App.lookup` are imported automatically into the current skill. To also use its instructions, insert the local name `lookup` into the current skill instructions like a concept:

```markdown
Use the following lookup instructions:

[#lookup#]
```

The included skill prepares its own concepts and nested skills before its instructions are inserted. The current skill can then use the imported instructions and tools exactly as an agent can. If the placeholder is omitted, the tools are still imported.

Tool names should be unique where possible. If the same name occurs more than once, the later nested skill takes precedence. A tool configured directly in the current skill takes precedence over its included skills, and a tool configured directly on the agent takes precedence over all skill tools. A warning is stored in the conversation when a tool is replaced this way.

## Custom prototypes

Apps can provide custom skill prototypes under `AI/Skills/*.php`. A prototype must implement `AiSkillInterface`; extending the behavior of `GenericSkill` is the normal starting point. The selected prototype controls the UXON properties offered by the Power UI editor.

## Using a skill from a concept

A concept can inject a skill's rendered instructions the same way `AgentInstructionsConcept` injects another agent's instructions. Use [`SkillInstructionsConcept`](api/docs/exface/Core/Docs/UXON/UXON_prototypes.md?selector=%5Caxenox%5CGenAI%5CAI%5CConcepts%5CSkillInstructionsConcept) with a `skill_alias`, for example to summarize several skills in an overview. Reference it by `alias` like any other concept, not by `class`. As with nested skills, set `use_instruction_boundary` to `false` when the concept's own placeholder is already wrapped by a surrounding skill:

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