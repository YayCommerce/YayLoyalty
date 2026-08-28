import type { ComponentPropsWithoutRef, ElementType } from 'react';

type StyledComponent<T extends ElementType> = (props: Omit<ComponentPropsWithoutRef<T>, 'className'>) => JSX.Element;

function CreateStyledDiv(className: string): StyledComponent<'div'> {
  return (props) => <div className={className} {...props} />;
}

function CreateStyledH1(className: string): StyledComponent<'h1'> {
  return (props) => <h1 className={className} {...props} />;
}

function CreateStyledH2(className: string): StyledComponent<'h2'> {
  return (props) => <h2 className={className} {...props} />;
}

function CreateStyledH3(className: string): StyledComponent<'h3'> {
  return (props) => <h3 className={className} {...props} />;
}

function CreateStyledH4(className: string): StyledComponent<'h4'> {
  return (props) => <h4 className={className} {...props} />;
}

function CreateStyledH5(className: string): StyledComponent<'h5'> {
  return (props) => <h5 className={className} {...props} />;
}

function CreateStyledH6(className: string): StyledComponent<'h6'> {
  return (props) => <h6 className={className} {...props} />;
}

function CreateStyledP(className: string): StyledComponent<'p'> {
  return (props) => <p className={className} {...props} />;
}

function CreateStyledA(className: string): StyledComponent<'a'> {
  return (props) => <a className={className} {...props} />;
}

function CreateStyledSpan(className: string): StyledComponent<'span'> {
  return (props) => <span className={className} {...props} />;
}
function CreateStyledLabel(className: string): StyledComponent<'label'> {
  return (props) => <label className={className} {...props} />;
}
function CreateStyledListItem(className: string): StyledComponent<'li'> {
  return (props) => <li className={className} {...props} />;
}

export const styled = {
  div: CreateStyledDiv,

  h1: CreateStyledH1,
  h2: CreateStyledH2,
  h3: CreateStyledH3,
  h4: CreateStyledH4,
  h5: CreateStyledH5,
  h6: CreateStyledH6,
  p: CreateStyledP,
  a: CreateStyledA,
  span: CreateStyledSpan,
  label: CreateStyledLabel,
  li: CreateStyledListItem,
};
