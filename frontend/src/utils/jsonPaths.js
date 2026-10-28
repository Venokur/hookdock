/**
 * Builds the tree the path picker renders and the paths `App\Support\JsonPath`
 * understands on the backend: dot segments plus numeric array indexes
 * (`body.items[0].sku`). Keys that contain a dot cannot be addressed, because
 * JsonPath would split them into separate segments — those nodes are shown but
 * not selectable.
 */

const MAX_PREVIEW = 48

export function joinPath(base, segment, index = null) {
  if (index !== null) {
    return `${base}[${index}]`
  }

  return base ? `${base}.${segment}` : String(segment)
}

/** A path segment is addressable only when JsonPath keeps it as one segment. */
export function isAddressableKey(key) {
  const value = String(key)

  return value !== '' && !value.includes('.')
}

export function typeOf(value) {
  if (value === null || value === undefined) {
    return 'null'
  }
  if (Array.isArray(value)) {
    return 'array'
  }

  return typeof value
}

export function typeLabel(type) {
  switch (type) {
    case 'string':
      return 'строка'
    case 'number':
      return 'число'
    case 'boolean':
      return 'логическое'
    case 'null':
      return 'null'
    case 'array':
      return 'массив'
    case 'object':
      return 'объект'
    default:
      return type
  }
}

export function previewOf(value, type = typeOf(value)) {
  if (type === 'array') {
    return value.length === 0 ? '[]' : `[${value.length}]`
  }

  if (type === 'object') {
    const keys = Object.keys(value)

    return keys.length === 0 ? '{}' : `{${keys.length}}`
  }

  if (type === 'null') {
    return 'null'
  }

  if (type === 'string') {
    const text = value.length > MAX_PREVIEW ? `${value.slice(0, MAX_PREVIEW)}…` : value

    return `"${text}"`
  }

  return String(value)
}

/**
 * @param {unknown} value
 * @param {string} path path of the node itself, e.g. `body`
 * @returns {object} node with `path`, `type`, `value`, `children`, `selectable`
 */
export function buildNode(value, path, key = null, addressable = true) {
  const type = typeOf(value)
  const children = []

  if (type === 'array') {
    value.forEach((item, index) => {
      children.push(buildNode(item, joinPath(path, null, index), `[${index}]`, addressable))
    })
  } else if (type === 'object') {
    for (const [childKey, childValue] of Object.entries(value)) {
      children.push(
        buildNode(childValue, joinPath(path, childKey), childKey, addressable && isAddressableKey(childKey)),
      )
    }
  }

  return {
    key: key ?? path,
    path,
    type,
    value,
    children,
    preview: previewOf(value, type),
    // A node below a key with a dot is unreachable too, so the flag cascades.
    selectable: addressable,
  }
}

export function buildTree(value, rootPath) {
  return buildNode(value, rootPath, rootPath)
}

/** Depth-first list of every node, used by search and path validation. */
export function flattenTree(node, list = []) {
  list.push(node)

  for (const child of node.children) {
    flattenTree(child, list)
  }

  return list
}

export function findNode(node, path) {
  if (!node) {
    return null
  }
  if (node.path === path) {
    return node
  }

  for (const child of node.children) {
    const match = findNode(child, path)
    if (match) {
      return match
    }
  }

  return null
}

