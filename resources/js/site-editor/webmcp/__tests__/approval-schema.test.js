import { afterEach, beforeEach, expect, test } from 'vitest';
import schemaArtifact from '../schemas.json';
import { installWebMCP } from '../index.js';

const schemas = schemaArtifact.operations;
const THROWAWAY = 'g3_throwaway_gated_destructive';

function registeredSchemas(capabilities) {
    const registered = new Map();
    Object.defineProperty(document, 'modelContext', {
        configurable: true,
        value: {
            registerTool(definition) {
                registered.set(definition.name, definition);
            },
        },
    });

    installWebMCP({
        bridge: { on() {} },
        config: { capabilities },
        coordinator: {
            currentRevision: () => null,
            compositionRevision: () => null,
        },
    });

    return window.__siteworks_webmcp__.sync().then(() => registered);
}

function installThrowaway(overrides = {}) {
    schemaArtifact.operations[THROWAWAY] = {
        readOnly: false,
        address: 'site',
        requiresApproval: true,
        destructive: true,
        positionalApprovalGap: false,
        sideEffects: 'Throwaway gated destructive write.',
        description: 'Throwaway gated destructive write.',
        inputSchema: {
            type: 'object',
            additionalProperties: false,
            properties: {
                marker: { type: 'string' },
            },
        },
        ...overrides,
    };
}

beforeEach(() => {
    delete document.modelContext;
});

afterEach(() => {
    delete schemaArtifact.operations[THROWAWAY];
});

test('approval capability adds approval_request_id to a gated registered schema', async () => {
    const registered = await registeredSchemas(['agent_tools', 'agent_approval']);
    const inputSchema = registered.get('siteworks.upload_image').inputSchema;

    expect(inputSchema.properties.approval_request_id).toEqual({ type: 'string', format: 'uuid' });
    expect(inputSchema.required ?? []).not.toContain('approval_request_id');
});

test('flag off registers schemas byte-identically to the committed artifact', async () => {
    const registered = await registeredSchemas(['agent_tools']);

    for (const [operation, schema] of Object.entries(schemas)) {
        const definition = registered.get(`siteworks.${operation}`);

        expect(JSON.stringify(definition.inputSchema)).toBe(JSON.stringify(schema.inputSchema));
        expect(definition.description).toBe(schema.description);
    }
});

test('approval capability adds boundary sentences to registered descriptions', async () => {
    const registered = await registeredSchemas(['agent_tools', 'agent_approval']);

    expect(registered.get('siteworks.upload_image').description)
        .toContain('This operation requires a one-use human approval.');
    expect(registered.get('siteworks.add_section').description)
        .toContain('Approval binding for positionally-addressed operations awaits stable section identifiers; this operation is not covered by the approval boundary.');
});

/*
 * Wrong implementation this fails against: the front end keeps a literal list that is
 * missing a gated operation. The throwaway is not remove_section and is not in
 * APPROVAL_OPS; a list-based tools.js therefore neither annotates it destructive nor
 * declares approval_request_id, so the model has no parameter to send the minted id in.
 */
test('a throwaway destructive-and-gated operation is annotated and parameterised with no front-end edit', async () => {
    installThrowaway();
    const registered = await registeredSchemas(['agent_tools', 'agent_approval']);
    const tool = registered.get(`siteworks.${THROWAWAY}`);

    expect(tool.annotations.destructiveHint).toBe(true);
    expect(tool.inputSchema.properties.approval_request_id).toEqual({ type: 'string', format: 'uuid' });
    expect(tool.inputSchema.required ?? []).not.toContain('approval_request_id');
    expect(tool.description).toContain('This operation requires a one-use human approval.');
});

/*
 * Independent oracle: the committed artefact's requiresApproval flags, which the PHP
 * suite pins to OperationRegistry::effectiveRequiresApproval. Wrong implementation:
 * the front end keeps a literal list that is missing a gated operation (undo_revision).
 */
test('every schema-declared gated operation gets approval_request_id and no other operation does', async () => {
    const registered = await registeredSchemas(['agent_tools', 'agent_approval']);
    const gated = Object.entries(schemas)
        .filter(([, schema]) => schema.requiresApproval === true)
        .map(([name]) => name);

    expect(gated.length).toBeGreaterThan(0);

    for (const [name, schema] of Object.entries(schemas)) {
        const properties = registered.get(`siteworks.${name}`).inputSchema.properties;

        if (schema.requiresApproval === true) {
            expect(properties.approval_request_id).toEqual({ type: 'string', format: 'uuid' });
        } else {
            expect(properties.approval_request_id).toBeUndefined();
        }
    }
});

/*
 * Independent oracle: the artefact's destructive flags, pinned in PHP to
 * OperationRegistry::effectiveDestructive. Wrong implementation: destructiveHint
 * is still `op === 'remove_section'`, so undo_revision / manage_video stay false.
 */
test('every schema-declared destructive operation annotates destructiveHint and no other operation does', async () => {
    const registered = await registeredSchemas(['agent_tools']);
    const destructive = Object.entries(schemas)
        .filter(([, schema]) => schema.destructive === true)
        .map(([name]) => name);

    expect(destructive.length).toBeGreaterThan(0);

    for (const [name, schema] of Object.entries(schemas)) {
        expect(registered.get(`siteworks.${name}`).annotations.destructiveHint)
            .toBe(schema.destructive === true);
    }

    for (const name of ['remove_section', 'undo_revision', 'manage_video']) {
        if (! schemas[name]) {
            continue;
        }

        expect(schemas[name].destructive).toBe(true);
        expect(registered.get(`siteworks.${name}`).annotations.destructiveHint).toBe(true);
    }
});
