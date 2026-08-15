import Select, { type StylesConfig, type SingleValue } from 'react-select'

export interface SearchableSelectOption {
  value: string
  label: string
  sublabel?: string
  isDisabled?: boolean
}

interface SearchableSelectProps {
  options: SearchableSelectOption[]
  value: string | null | undefined
  onChange: (value: string) => void
  placeholder?: string
  isDisabled?: boolean
  isClearable?: boolean
  noOptionsMessage?: string
  className?: string
  'aria-label'?: string
  id?: string
}

const styles: StylesConfig<SearchableSelectOption, false> = {
  control: (base, state) => ({
    ...base,
    minHeight: 42,
    borderRadius: 10,
    borderColor: state.isFocused ? '#b7e9d1' : '#dde7df',
    boxShadow: state.isFocused ? '0 0 0 2px #b7e9d1' : 'none',
    '&:hover': { borderColor: state.isFocused ? '#b7e9d1' : '#dde7df' },
    fontSize: 14,
    opacity: state.isDisabled ? 0.6 : 1,
  }),
  valueContainer: (base) => ({ ...base, padding: '2px 12px' }),
  input: (base) => ({ ...base, color: '#17231d', margin: 0, padding: 0 }),
  placeholder: (base) => ({ ...base, color: '#657267' }),
  singleValue: (base) => ({ ...base, color: '#17231d' }),
  menu: (base) => ({ ...base, zIndex: 30, borderRadius: 10, overflow: 'hidden', border: '1px solid #dde7df' }),
  menuList: (base) => ({ ...base, padding: 0 }),
  option: (base, state) => ({
    ...base,
    fontSize: 14,
    backgroundColor: state.isSelected ? '#0f6b45' : state.isFocused ? '#e8f6ef' : '#ffffff',
    color: state.isSelected ? '#ffffff' : '#17231d',
    cursor: 'pointer',
  }),
  indicatorSeparator: () => ({ display: 'none' }),
}

/**
 * Select searchable berbasis react-select, dipakai untuk semua dropdown yang
 * menampilkan daftar item PDO (bisa puluhan/ratusan item) — mengetik untuk
 * filter, bukan scroll manual.
 */
export function SearchableSelect({
  options,
  value,
  onChange,
  placeholder = 'Cari & pilih...',
  isDisabled = false,
  isClearable = false,
  noOptionsMessage = 'Tidak ditemukan',
  className,
  id,
  ...rest
}: SearchableSelectProps) {
  const selected = options.find((o) => o.value === value) ?? null

  return (
    <Select<SearchableSelectOption, false>
      inputId={id}
      classNamePrefix="searchable-select"
      className={className}
      styles={styles}
      options={options}
      value={selected}
      onChange={(opt: SingleValue<SearchableSelectOption>) => onChange(opt?.value ?? '')}
      placeholder={placeholder}
      isDisabled={isDisabled}
      isClearable={isClearable}
      noOptionsMessage={() => noOptionsMessage}
      formatOptionLabel={(opt) => (
        opt.sublabel ? (
          <div>
            <div>{opt.label}</div>
            <div className="text-xs text-muted">{opt.sublabel}</div>
          </div>
        ) : opt.label
      )}
      {...rest}
    />
  )
}
