export function resolveCurrentFormRevision(config, state) {
    return config.currentRevisionId ?? state.revisionId ?? '';
}
